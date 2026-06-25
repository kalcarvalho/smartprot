package com.sysfactor.apps.smartprot.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.VpnService
import android.os.ParcelFileDescriptor
import android.util.Log
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.sysfactor.apps.smartprot.data.model.Rule
import com.sysfactor.apps.smartprot.data.repository.DeviceRepository
import com.sysfactor.apps.smartprot.ui.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.flow.asStateFlow
import java.util.concurrent.ConcurrentHashMap

class PolicyVpnService : VpnService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val vpnLock = Any()
    private var vpnInterface: ParcelFileDescriptor? = null
    private var currentRules: List<Rule> = emptyList()
    private var forwarder: TunForwarder? = null

    // Server-provided app -> domains mapping (from the policy sync's app_domains
    // field). This is the dynamic source of truth; KNOWN_APP_DOMAINS below is only
    // an offline baseline merged in underneath it.
    private var serverAppDomains: Map<String, Set<String>> = emptyMap()

    // Final expanded sets actually handed to the forwarder, kept as instance state so
    // startForwarder() (called on every VPN rebuild) reuses the same expansion that
    // applyRules() computed instead of recomputing it from currentRules alone.
    private var resolvedBlockedDomains: Set<String> = emptySet()
    private var resolvedBlockedIps: Set<Int> = emptySet()
    private var resolvedAllowedDomains: Set<String> = emptySet()
    private var resolvedAllowedIps: Set<Int> = emptySet()
    private var resolvedDefaultNetwork: String = "allowed"

    // domain -> app package (nullable) observed in traffic, buffered until the next
    // periodic flush reports them to the server via DeviceRepository.reportDomains().
    private val observedDomains = ConcurrentHashMap<String, String?>()
    private var domainReportLoopStarted = false
    private val usageTracker by lazy { UsageTracker(this) }
    private var policySyncLoopStarted = false

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        startForeground(NOTIFICATION_ID, buildNotification())
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> start()
            ACTION_STOP -> stop()
            ACTION_UPDATE_RULES -> {
                intent.getStringExtra(EXTRA_RULES_JSON)?.let { json ->
                    val rulesType = object : TypeToken<List<Rule>>() {}.type
                    val rules: List<Rule> = Gson().fromJson(json, rulesType)

                    val appDomains: Map<String, List<String>>? = intent.getStringExtra(EXTRA_APP_DOMAINS_JSON)?.let { domainsJson ->
                        val domainsType = object : TypeToken<Map<String, List<String>>>() {}.type
                        Gson().fromJson(domainsJson, domainsType)
                    }
                    val defaultNetwork = intent.getStringExtra(EXTRA_DEFAULT_NETWORK) ?: "allowed"
                    val protectionEnabled = intent.getBooleanExtra(EXTRA_PROTECTION_ENABLED, true)

                    applyRules(rules, appDomains, defaultNetwork, protectionEnabled)
                }
            }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        stop()
        scope.cancel()
        super.onDestroy()
    }

    private fun start() {
        scope.launch {
            syncImmediately()
        }
        startDomainReportLoop()
        startPolicySyncLoop()
    }

    /**
     * Polls the policy every minute while the VPN foreground service is alive.
     * WorkManager's PeriodicWorkRequest has a hard 15-minute floor enforced by
     * Android itself, so a faster cadence than that can only be done from a
     * running foreground service like this one, not from PolicySyncWorker.
     */
    private fun startPolicySyncLoop() {
        if (policySyncLoopStarted) return
        policySyncLoopStarted = true
        scope.launch {
            while (true) {
                delay(POLICY_SYNC_LOOP_INTERVAL_MS)
                val repo = DeviceRepository(this@PolicyVpnService)
                try {
                    repo.syncPolicy().onSuccess { policy ->
                        applyRules(policy.rules, policy.appDomains, policy.settings?.defaultNetwork ?: "allowed", policy.settings?.protectionEnabled ?: true)
                    }
                } catch (_: Exception) {}
            }
        }
    }

    private fun stop() {
        teardownTunnel()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    /**
     * Closes the TUN interface and the custom packet relay, handing the device
     * back to direct/native networking, but keeps this foreground service (and
     * its periodic sync loops) alive so paused protection can resume later
     * without re-prompting for VPN consent. Pausing protection used to only
     * clear the rule lists while leaving the hand-rolled TUN relay running for
     * every connection -- which is slower and less reliable than native
     * networking even with zero block rules, and looked like YouTube/etc still
     * being "blocked" despite protection being off.
     */
    private fun teardownTunnel() {
        scope.launch { flushObservedDomains() }
        stopForwarder()
        vpnInterface?.close()
        vpnInterface = null
        isRunning.value = false
        saveVpnActive(false)
        blockedAppPackages.value = emptySet()
    }

    private suspend fun syncImmediately() {
        val repo = DeviceRepository(this)
        try {
            repo.sendHeartbeat(policyVersion = null, battery = null, vpnActive = true)
        } catch (_: Exception) {}
        var synced = false
        for (attempt in 0 until 3) {
            if (synced) break
            try {
                repo.syncPolicy().onSuccess { policy ->
                    applyRules(policy.rules, policy.appDomains, policy.settings?.defaultNetwork ?: "allowed", policy.settings?.protectionEnabled ?: true)
                    synced = true
                }
            } catch (_: Exception) {}
            if (!synced && attempt < 2) delay(2000)
        }
        if (!synced) {
            Log.w(TAG, "Initial policy sync failed after 3 attempts, starting VPN without rules")
            rebuildVpn(emptySet())
            saveVpnActive(true)
        }
    }

    /** Periodically reports buffered observed domains so the parent panel can show them. */
    private fun startDomainReportLoop() {
        if (domainReportLoopStarted) return
        domainReportLoopStarted = true
        scope.launch {
            while (true) {
                delay(DOMAIN_REPORT_INTERVAL_MS)
                flushObservedDomains()
            }
        }
    }

    private suspend fun flushObservedDomains() {
        tickUsage()

        if (observedDomains.isEmpty()) return
        val snapshot = HashMap(observedDomains)
        observedDomains.keys.removeAll(snapshot.keys)

        val repo = DeviceRepository(this)
        snapshot.entries
            .groupBy({ it.value }, { it.key })
            .forEach { (appPackage, domains) ->
                repo.reportDomains(domains, appPackage)
            }
    }

    /**
     * Approximates one minute of usage for every rule with a daily limit whose
     * app/domain had traffic in this window. Uses both the TUN forwarder's
     * currently-open connections (catches a long-lived stream that hasn't
     * re-handshaked) and the just-observed-domains buffer (catches short
     * requests that already closed by the time this tick runs).
     */
    private fun tickUsage() {
        val activeDomains = (forwarder?.activeDomainsSnapshot() ?: emptySet()) + observedDomains.keys
        if (activeDomains.isEmpty()) return

        for (rule in currentRules) {
            val limit = rule.dailyLimitMinutes ?: continue
            val ruleId = rule.id ?: continue
            val matches = when (rule.type) {
                "domain", "url" -> activeDomains.any { domainMatches(it, setOf(rule.target.lowercase())) }
                "app" -> {
                    val expanded = (KNOWN_APP_DOMAINS[rule.target] ?: emptySet()) + (serverAppDomains[rule.target] ?: emptySet())
                    activeDomains.any { domainMatches(it, expanded) }
                }
                else -> false
            }
            if (matches) {
                usageTracker.recordTick(ruleId)
            }
        }

        // Re-apply immediately so a rule that just crossed its daily limit
        // starts blocking this tick, instead of waiting for the next server
        // policy sync to notice the new usage total.
        applyRules(currentRules, null, resolvedDefaultNetwork)
    }

    private fun domainMatches(domain: String, set: Set<String>): Boolean =
        set.any { entry -> domain == entry || domain.endsWith(".$entry") }

    /** A rule's network flips to "blocked" once its daily usage limit is reached, regardless of its configured action. */
    private fun effectiveNetwork(rule: Rule): String {
        val limit = rule.dailyLimitMinutes ?: return rule.network
        val ruleId = rule.id ?: return rule.network
        return if (usageTracker.minutesUsedToday(ruleId) >= limit) "blocked" else rule.network
    }

    /** Called from the TUN forwarder for every domain seen in traffic, blocked or not. */
    private fun recordObservedDomain(domain: String, uid: Int) {
        val appPackage = resolvePackageForUid(uid)
        observedDomains.compute(domain) { _, existing -> existing ?: appPackage }
    }

    private fun resolvePackageForUid(uid: Int): String? {
        if (uid < 0) return null
        return try {
            packageManager.getPackagesForUid(uid)?.firstOrNull()
        } catch (_: Exception) {
            null
        }
    }

    private fun saveVpnActive(active: Boolean) {
        getSharedPreferences(PREF_VPN_STATE, MODE_PRIVATE)
            .edit().putBoolean(KEY_VPN_ACTIVE, active).apply()
    }

    fun applyRules(
        rules: List<Rule>,
        appDomains: Map<String, List<String>>? = null,
        defaultNetwork: String = "allowed",
        protectionEnabled: Boolean = true
    ) {
        synchronized(vpnLock) {
            if (!protectionEnabled) {
                if (vpnInterface != null) {
                    Log.i(TAG, "Protection paused -- tearing down the TUN tunnel")
                    teardownTunnel()
                }
                currentRules = emptyList()
                return
            }

            currentRules = rules.filter { !isExpired(it) && isWithinSchedule(it) }
            saveVpnActive(true)

            if (appDomains != null) {
                serverAppDomains = appDomains.mapValues { (_, domains) ->
                    domains.map { it.lowercase() }.toSet()
                }
            }

            val blockedApps = currentRules
                .filter { it.type == "app" && effectiveNetwork(it) == "blocked" }
                .map { it.target }
                .toSet()
            val allowedApps = currentRules
                .filter { it.type == "app" && effectiveNetwork(it) == "allowed" }
                .map { it.target }
                .toSet()

            val blockedDomains = currentRules
                .filter { (it.type == "domain" || it.type == "url") && effectiveNetwork(it) == "blocked" }
                .map { it.target.lowercase() }
                .toMutableSet()
            val allowedDomains = currentRules
                .filter { (it.type == "domain" || it.type == "url") && effectiveNetwork(it) == "allowed" }
                .map { it.target.lowercase() }
                .toMutableSet()

            val blockedIps = currentRules
                .filter { it.type == "ip" && effectiveNetwork(it) == "blocked" }
                .mapNotNull { ipToInt(it.target) }
                .toMutableSet()
            val allowedIps = currentRules
                .filter { it.type == "ip" && effectiveNetwork(it) == "allowed" }
                .mapNotNull { ipToInt(it.target) }
                .toMutableSet()

            // Expand "app" rules into the concrete domains that app talks to: the
            // server's AppDomainMapping (dynamic, can be extended without an app
            // update) merged with a small built-in baseline for offline use.
            for (appPkg in blockedApps) {
                KNOWN_APP_DOMAINS[appPkg]?.let { blockedDomains.addAll(it) }
                serverAppDomains[appPkg]?.let { blockedDomains.addAll(it) }
            }
            for (appPkg in allowedApps) {
                KNOWN_APP_DOMAINS[appPkg]?.let { allowedDomains.addAll(it) }
                serverAppDomains[appPkg]?.let { allowedDomains.addAll(it) }
            }

            resolvedBlockedDomains = blockedDomains
            resolvedBlockedIps = blockedIps
            resolvedAllowedDomains = allowedDomains
            resolvedAllowedIps = allowedIps
            resolvedDefaultNetwork = defaultNetwork

            if (vpnInterface == null || blockedApps != blockedAppPackages.value) {
                rebuildVpn(blockedApps)
            }
            forwarder?.updateRules(blockedDomains, blockedIps, allowedDomains, allowedIps, defaultNetwork)
            blockedAppPackages.value = blockedApps
            Log.i(TAG, "Rules updated: ${blockedApps.size} apps, ${blockedDomains.size} domains, ${blockedIps.size} IPs, default=$defaultNetwork")
        }
    }

    private fun rebuildVpn(blockedApps: Set<String>) {
        val builder = Builder()
            .setSession(getString(com.sysfactor.apps.smartprot.R.string.vpn_session_name))
            .setConfigureIntent(
                PendingIntent.getActivity(
                    this, 0, Intent(this, MainActivity::class.java),
                    PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
                )
            )

        builder.addAddress("10.0.0.2", 32)
        builder.addRoute("0.0.0.0", 0)
        builder.addDnsServer("1.1.1.1")
        builder.addDnsServer("8.8.8.8")
        // Only SmartProt itself bypasses the VPN (loop prevention). Every other
        // app must be tunneled — domain/IP rules are app-agnostic (e.g. a browser
        // hitting a blocked domain), so excluding apps without their own "app"
        // rule from the tunnel silently broke domain/IP blocking entirely.
        builder.addDisallowedApplication(packageName)
        Log.i(TAG, "VPN rebuild: ${blockedApps.size} apps explicitly blocked, all other traffic tunneled")

        vpnInterface?.close()
        vpnInterface = builder.establish()
        isRunning.value = vpnInterface != null

        if (vpnInterface != null) {
            startForwarder()
        }
    }

    fun getCurrentBlockedPackages(): Set<String> = blockedAppPackages.value

    private fun startForwarder() {
        stopForwarder()
        val cm = getSystemService(ConnectivityManager::class.java)

        // Reuse the same expansion applyRules() already computed (including the
        // app -> domains expansion) instead of recomputing from currentRules alone,
        // which previously dropped that expansion on every VPN rebuild.
        val blockedDomains = resolvedBlockedDomains
        val blockedIps = resolvedBlockedIps
        val allowedDomains = resolvedAllowedDomains
        val allowedIps = resolvedAllowedIps
        val defaultNetwork = resolvedDefaultNetwork

        val repo = DeviceRepository(this)

        vpnInterface?.let { fd ->
            forwarder = TunForwarder(
                connectivityManager = cm,
                blockedDomains = blockedDomains,
                blockedIps = blockedIps,
                allowedDomains = allowedDomains,
                allowedIps = allowedIps,
                defaultNetwork = defaultNetwork,
                onBlocked = { type, target, ip, port ->
                    kotlinx.coroutines.runBlocking {
                        repo.reportEvent("connection_$type", mapOf(
                            "target" to target,
                            "ip" to ip,
                            "port" to port
                        ))
                    }
                },
                onDomainObserved = { domain, uid -> recordObservedDomain(domain, uid) }
            )
            forwarder?.start(fd)
            Log.i(TAG, "TUN forwarder started")
        }
    }

    private fun stopForwarder() {
        forwarder?.stop()
        forwarder = null
    }

    private fun isExpired(rule: Rule): Boolean {
        if (rule.until == null) return false
        return try {
            java.time.Instant.parse(rule.until).isBefore(java.time.Instant.now())
        } catch (_: Exception) {
            false
        }
    }

    /**
     * Evaluated against the device's own local clock/timezone -- a schedule of
     * "00:00-05:59" means that window wherever the device physically is, not a
     * server-side timezone. Re-checked on every policy sync (currently every
     * minute while the VPN is running), so a rule activates/deactivates within
     * that resolution without needing a fresh server policy.
     */
    private fun isWithinSchedule(rule: Rule): Boolean {
        val schedule = rule.schedule ?: return true
        val now = java.time.LocalDateTime.now()

        val days = schedule.days
        if (!days.isNullOrEmpty()) {
            val dayCode = when (now.dayOfWeek) {
                java.time.DayOfWeek.MONDAY -> "mon"
                java.time.DayOfWeek.TUESDAY -> "tue"
                java.time.DayOfWeek.WEDNESDAY -> "wed"
                java.time.DayOfWeek.THURSDAY -> "thu"
                java.time.DayOfWeek.FRIDAY -> "fri"
                java.time.DayOfWeek.SATURDAY -> "sat"
                java.time.DayOfWeek.SUNDAY -> "sun"
            }
            if (dayCode !in days) return false
        }

        val startsAt = schedule.startsAt
        val endsAt = schedule.endsAt
        if (startsAt == null || endsAt == null) return true

        return try {
            val start = java.time.LocalTime.parse(startsAt)
            val end = java.time.LocalTime.parse(endsAt)
            val nowTime = now.toLocalTime()
            if (!start.isAfter(end)) {
                !nowTime.isBefore(start) && !nowTime.isAfter(end)
            } else {
                // Overnight window (e.g. 22:00-06:00) wraps past midnight.
                !nowTime.isBefore(start) || !nowTime.isAfter(end)
            }
        } catch (_: Exception) {
            true
        }
    }

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(com.sysfactor.apps.smartprot.R.string.channel_vpn_name),
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = getString(com.sysfactor.apps.smartprot.R.string.channel_vpn_description)
        }
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(channel)
    }

    private fun buildNotification(): Notification {
        val openIntent = Intent(this, MainActivity::class.java)
        val openPending = PendingIntent.getActivity(
            this, 0, openIntent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

        return Notification.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(com.sysfactor.apps.smartprot.R.string.notification_vpn_title))
            .setContentText(getString(com.sysfactor.apps.smartprot.R.string.notification_vpn_text))
            .setSmallIcon(com.sysfactor.apps.smartprot.R.drawable.ic_notification)
            .setContentIntent(openPending)
            .setOngoing(true)
            .build()
    }

    private fun ipToInt(ip: String): Int? {
        val parts = ip.split(".")
        if (parts.size != 4) return null
        return try {
            (parts[0].toInt() and 0xFF shl 24) or
                    (parts[1].toInt() and 0xFF shl 16) or
                    (parts[2].toInt() and 0xFF shl 8) or
                    (parts[3].toInt() and 0xFF)
        } catch (_: NumberFormatException) { null }
    }

    companion object {
        const val ACTION_START = "com.sysfactor.apps.smartprot.action.START_VPN"
        const val ACTION_STOP = "com.sysfactor.apps.smartprot.action.STOP_VPN"
        const val ACTION_UPDATE_RULES = "com.sysfactor.apps.smartprot.action.UPDATE_RULES"
        const val EXTRA_RULES_JSON = "rules_json"
        const val EXTRA_APP_DOMAINS_JSON = "app_domains_json"
        const val EXTRA_DEFAULT_NETWORK = "default_network"
        const val EXTRA_PROTECTION_ENABLED = "protection_enabled"
        private const val CHANNEL_ID = "vpn_service"
        private const val NOTIFICATION_ID = 1
        private const val TAG = "PolicyVpnService"
        private const val PREF_VPN_STATE = "smartprot_vpn_state"
        private const val KEY_VPN_ACTIVE = "vpn_active"
        private const val DOMAIN_REPORT_INTERVAL_MS = 60_000L
        private const val POLICY_SYNC_LOOP_INTERVAL_MS = 60_000L

        val isRunning = MutableStateFlow(false)
        val blockedAppPackages = MutableStateFlow<Set<String>>(emptySet())

        fun wasVpnActive(context: Context): Boolean {
            return context.getSharedPreferences(PREF_VPN_STATE, Context.MODE_PRIVATE)
                .getBoolean(KEY_VPN_ACTIVE, false)
        }

        // Offline baseline only — merged with the dynamic, server-provided app_domains
        // (see serverAppDomains/applyRules above). New apps should be added via the
        // AppDomainMapping table on the server, not here, so blocking stays dynamic.
        val KNOWN_APP_DOMAINS: Map<String, Set<String>> = mapOf(
            "com.google.android.youtube" to setOf(
                "youtube.com", "www.youtube.com", "m.youtube.com",
                "youtubekids.com", "www.youtubekids.com",
                "googlevideo.com", "ytimg.com", "youtu.be",
                "youtubei.googleapis.com", "youtube.googleapis.com",
                "youtube-nocookie.com", "www.youtube-nocookie.com"
            ),
            "com.dazn" to setOf("dazn.com", "www.dazn.com", "dazn.com.cdn.cloudflare.net"),
            "com.netflix.mediaclient" to setOf("netflix.com", "www.netflix.com", "nflxvideo.net", "nflximg.net", "nflxext.com"),
            "com.spotify.music" to setOf("spotify.com", "www.spotify.com", "scdn.co", "spotifycdn.net"),
            "com.amazon.avod.thirdpartyclient" to setOf("primevideo.com", "www.primevideo.com"),
            "br.com.band.bandplay" to setOf("bandplay.com.br", "www.bandplay.com.br"),
            "br.com.globo.globoplay" to setOf("globoplay.globo.com", "globo.com", "gstatics.com"),
            "tv.pluto.android" to setOf("pluto.tv", "plutotv.net", "pluto.tv.cdn.cloudflare.net"),
            "de.zdf.androidapp" to setOf("zdf.de", "www.zdf.de", "zdfcloud.com", "zdf.de.cdn.cloudflare.net"),
            "ard.mediathek" to setOf("ardmediathek.de", "www.ardmediathek.de", "ard.de"),
            "com.zattoo.player" to setOf("zattoo.com", "www.zattoo.com")
        )
    }
}
