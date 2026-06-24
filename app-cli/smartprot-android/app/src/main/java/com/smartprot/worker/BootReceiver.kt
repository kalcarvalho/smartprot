package com.smartprot.worker

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.smartprot.service.PolicyVpnService

class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            val prefs = context.getSharedPreferences("smartprot_auth", Context.MODE_PRIVATE)
            if (prefs.contains("device_id")) {
                HeartbeatWorker.enqueue(context)
                PolicySyncWorker.enqueue(context)
            }
        }
    }
}
