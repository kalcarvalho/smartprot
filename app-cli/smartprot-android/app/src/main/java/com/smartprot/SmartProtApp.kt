package com.smartprot

import android.app.Application
import com.smartprot.data.api.ApiClient
import com.smartprot.worker.HeartbeatWorker
import com.smartprot.worker.PolicySyncWorker

class SmartProtApp : Application() {

    override fun onCreate() {
        super.onCreate()
        ApiClient.init(this)

        if (isRegistered()) {
            HeartbeatWorker.enqueue(this)
            PolicySyncWorker.enqueue(this)
        }
    }

    private fun isRegistered(): Boolean {
        val prefs = getSharedPreferences("smartprot_auth", MODE_PRIVATE)
        return prefs.contains("device_id") && prefs.contains("device_token")
    }
}
