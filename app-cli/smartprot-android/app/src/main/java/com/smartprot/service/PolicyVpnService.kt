package com.smartprot.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.net.ConnectivityManager
import android.net.Network
import android.net.VpnService
import android.os.ParcelFileDescriptor
import android.util.Log
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.smartprot.data.model.Rule
import com.smartprot.ui.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

class PolicyVpnService : VpnService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var vpnInterface: ParcelFileDescriptor? = null
    private var currentRules: List<Rule> = emptyList()
    private var forwarder: TunForwarder? = null

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
                    val type = object : TypeToken<List<Rule>>() {}.type
                    val rules: List<Rule> = Gson().fromJson(json, type)
                    applyRules(rules)
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
        val builder = Builder()
            .setSession(getString(com.smartprot.R.string.vpn_session_name))
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
        builder.addDisallowedApplication(packageName)

        vpnInterface?.close()
        vpnInterface = builder.establish()
        isRunning.value = vpnInterface != null

        if (vpnInterface != null) {
            startForwarder()
        }
    }

    private fun stop() {
        stopForwarder()
        vpnInterface?.close()
        vpnInterface = null
        isRunning.value = false
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    fun applyRules(rules: List<Rule>) {
        currentRules = rules.filter { !isExpired(it) }

        val blockedApps = currentRules
            .filter { it.type == "app" && it.network == "blocked" }
            .map { it.target }
            .toSet()

        val blockedDomains = currentRules
            .filter { it.type == "domain" && it.network == "blocked" }
            .map { it.target.lowercase() }
            .toMutableSet()

        val blockedIps = currentRules
            .filter { it.type == "ip" && it.network == "blocked" }
            .mapNotNull { ipToInt(it.target) }
            .toMutableSet()

        for (appPkg in blockedApps) {
            val knownDomains = KNOWN_APP_DOMAINS[appPkg]
            if (knownDomains != null) {
                blockedDomains.addAll(knownDomains)
            }
        }

        val builder = Builder()
            .setSession(getString(com.smartprot.R.string.vpn_session_name))
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
        builder.addDisallowedApplication(packageName)

        vpnInterface?.close()
        vpnInterface = builder.establish()
        isRunning.value = vpnInterface != null

        if (vpnInterface != null) {
            startForwarder()
        }

        forwarder?.updateRules(blockedDomains, blockedIps)

        blockedAppPackages.value = blockedApps
    }

    fun getCurrentBlockedPackages(): Set<String> = blockedAppPackages.value

    private fun startForwarder() {
        stopForwarder()
        val cm = getSystemService(ConnectivityManager::class.java)
        val activeNetwork = cm.activeNetwork

        val blockedDomains = currentRules
            .filter { it.type == "domain" && it.network == "blocked" }
            .map { it.target.lowercase() }
            .toSet()

        val blockedIps = currentRules
            .filter { it.type == "ip" && it.network == "blocked" }
            .mapNotNull { ipToInt(it.target) }
            .toSet()

        vpnInterface?.let { fd ->
            forwarder = TunForwarder(activeNetwork, blockedDomains, blockedIps)
            forwarder?.start(fd)
            Log.i(TAG, "TUN forwarder started on network: $activeNetwork")
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

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(com.smartprot.R.string.channel_vpn_name),
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = getString(com.smartprot.R.string.channel_vpn_description)
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
            .setContentTitle(getString(com.smartprot.R.string.notification_vpn_title))
            .setContentText(getString(com.smartprot.R.string.notification_vpn_text))
            .setSmallIcon(com.smartprot.R.drawable.ic_notification)
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
        const val ACTION_START = "com.smartprot.action.START_VPN"
        const val ACTION_STOP = "com.smartprot.action.STOP_VPN"
        const val ACTION_UPDATE_RULES = "com.smartprot.action.UPDATE_RULES"
        const val EXTRA_RULES_JSON = "rules_json"
        private const val CHANNEL_ID = "vpn_service"
        private const val NOTIFICATION_ID = 1
        private const val TAG = "PolicyVpnService"

        val isRunning = MutableStateFlow(false)
        val blockedAppPackages = MutableStateFlow<Set<String>>(emptySet())

        val KNOWN_APP_DOMAINS: Map<String, Set<String>> = mapOf(
            "com.google.android.youtube" to setOf(
                "youtube.com", "www.youtube.com", "m.youtube.com",
                "youtubekids.com", "www.youtubekids.com",
                "googlevideo.com", "ytimg.com", "youtu.be",
                "ggpht.com", "googleapis.com",
                "youtubei.googleapis.com", "youtube.googleapis.com",
                "gvt2.com", "gvt3.com",
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
