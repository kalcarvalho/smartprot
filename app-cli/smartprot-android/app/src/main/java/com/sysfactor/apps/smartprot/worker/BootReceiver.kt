package com.sysfactor.apps.smartprot.worker

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.util.Log
import com.sysfactor.apps.smartprot.service.PolicyVpnService
import com.sysfactor.apps.smartprot.ui.MainActivity

class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            val prefs = context.getSharedPreferences("smartprot_auth", Context.MODE_PRIVATE)
            if (!prefs.contains("device_id")) return

            HeartbeatWorker.enqueue(context)
            PolicySyncWorker.enqueue(context)

            if (PolicyVpnService.wasVpnActive(context)) {
                val vpnIntent = VpnService.prepare(context)
                if (vpnIntent == null) {
                    val startIntent = Intent(context, PolicyVpnService::class.java).apply {
                        action = PolicyVpnService.ACTION_START
                    }
                    try {
                        context.startForegroundService(startIntent)
                    } catch (e: Exception) {
                        context.startService(startIntent)
                    }
                } else {
                    vpnIntent.flags = Intent.FLAG_ACTIVITY_NEW_TASK
                    val openIntent = Intent(context, MainActivity::class.java).apply {
                        flags = Intent.FLAG_ACTIVITY_NEW_TASK
                    }
                    val pendingIntent = PendingIntent.getActivity(
                        context, 0, openIntent,
                        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
                    )
                    try {
                        pendingIntent.send()
                    } catch (_: Exception) {}
                }
            }
        }
    }
}
