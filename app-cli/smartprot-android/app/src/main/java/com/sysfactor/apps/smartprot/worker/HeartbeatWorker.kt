package com.sysfactor.apps.smartprot.worker

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.util.Log
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.sysfactor.apps.smartprot.data.repository.DeviceRepository
import com.sysfactor.apps.smartprot.service.PolicyVpnService
import kotlinx.coroutines.runBlocking
import java.util.concurrent.TimeUnit

class HeartbeatWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    override fun doWork(): Result {
        val repo = DeviceRepository(applicationContext)

        if (!repo.isRegistered()) {
            Log.w(TAG, "Skipping heartbeat — device not registered")
            return Result.retry()
        }

        return try {
            val battery = getBatteryPercent()
            val vpnActive = isVpnActive()
            val policyVersion = getCurrentPolicyVersion()

            val success = runBlocking {
                repo.sendHeartbeat(
                    policyVersion = policyVersion,
                    battery = battery,
                    vpnActive = vpnActive
                )
            }

            if (success.isSuccess) Result.success() else Result.retry()
        } catch (e: Exception) {
            Log.e(TAG, "Heartbeat failed", e)
            Result.retry()
        }
    }

    private fun getBatteryPercent(): Int? {
        val batteryManager = applicationContext.getSystemService(Context.BATTERY_SERVICE) as? BatteryManager
        return batteryManager?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
    }

    private fun isVpnActive(): Boolean {
        return PolicyVpnService.isRunning.value
    }

    private fun getCurrentPolicyVersion(): Int? {
        val prefs = applicationContext.getSharedPreferences(
            "smartprot_policy", Context.MODE_PRIVATE
        )
        val version = prefs.getInt(PREF_POLICY_VERSION, -1)
        return if (version >= 0) version else null
    }

    companion object {
        private const val TAG = "HeartbeatWorker"
        private const val PREF_POLICY_VERSION = "current_policy_version"

        fun enqueue(context: Context, intervalMinutes: Long = 15) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<HeartbeatWorker>(
                intervalMinutes, TimeUnit.MINUTES
            )
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.LINEAR, 1, TimeUnit.MINUTES)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                "heartbeat",
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }

        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork("heartbeat")
        }
    }
}
