package com.sysfactor.apps.smartprot

import android.app.Application
import android.content.Intent
import android.net.VpnService
import com.sysfactor.apps.smartprot.data.api.ApiClient
import com.sysfactor.apps.smartprot.service.PolicyVpnService
import com.sysfactor.apps.smartprot.worker.HeartbeatWorker
import com.sysfactor.apps.smartprot.worker.PolicySyncWorker

class SmartProtApp : Application() {

    override fun onCreate() {
        super.onCreate()
        ApiClient.init(this)

        if (isRegistered()) {
            HeartbeatWorker.enqueue(this)
            PolicySyncWorker.enqueue(this)

            if (PolicyVpnService.wasVpnActive(this) && !PolicyVpnService.isRunning.value) {
                val vpnIntent = VpnService.prepare(this)
                if (vpnIntent == null) {
                    try {
                        val intent = Intent(this, PolicyVpnService::class.java).apply {
                            action = PolicyVpnService.ACTION_START
                        }
                        startForegroundService(intent)
                    } catch (_: Exception) {
                        getSharedPreferences("smartprot_vpn", MODE_PRIVATE)
                            .edit().putBoolean("vpn_pending", true).apply()
                    }
                }
            }
        }
    }

    private fun isRegistered(): Boolean {
        val prefs = getSharedPreferences("smartprot_auth", MODE_PRIVATE)
        return prefs.contains("device_id") && prefs.contains("device_token")
    }
}
