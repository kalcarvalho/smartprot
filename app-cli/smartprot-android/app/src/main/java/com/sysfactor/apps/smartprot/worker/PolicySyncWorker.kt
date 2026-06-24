package com.sysfactor.apps.smartprot.worker

import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.google.gson.Gson
import com.sysfactor.apps.smartprot.data.repository.DeviceRepository
import com.sysfactor.apps.smartprot.service.PolicyVpnService
import kotlinx.coroutines.runBlocking
import java.util.concurrent.TimeUnit

class PolicySyncWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    override fun doWork(): Result {
        val repo = DeviceRepository(applicationContext)

        if (!repo.isRegistered()) {
            Log.w(TAG, "Skipping policy sync — device not registered")
            return Result.retry()
        }

        return try {
            val result = runBlocking { repo.syncPolicy() }

            result.fold(
                onSuccess = { policy ->
                    savePolicyVersion(policy.version)

                    if (PolicyVpnService.isRunning.value) {
                        val rulesJson = Gson().toJson(policy.rules)
                        val intent = Intent(applicationContext, PolicyVpnService::class.java).apply {
                            action = PolicyVpnService.ACTION_UPDATE_RULES
                            putExtra(PolicyVpnService.EXTRA_RULES_JSON, rulesJson)
                            policy.appDomains?.let { appDomains ->
                                putExtra(PolicyVpnService.EXTRA_APP_DOMAINS_JSON, Gson().toJson(appDomains))
                            }
                        }
                        applicationContext.startService(intent)
                    }

                    val blockedCount = policy.rules.count { it.network == "blocked" }
                    Log.i(TAG, "Policy v${policy.version} synced ($blockedCount blocked rules)")

                    runBlocking {
                        repo.reportEvent("policy_applied", mapOf(
                            "version" to policy.version,
                            "rules_count" to policy.rules.size
                        ))
                    }

                    Result.success()
                },
                onFailure = {
                    Log.w(TAG, "Policy sync failed, will retry")
                    Result.retry()
                }
            )
        } catch (e: Exception) {
            Log.e(TAG, "Policy sync error", e)
            Result.retry()
        }
    }

    private fun savePolicyVersion(version: Int) {
        val prefs = applicationContext.getSharedPreferences(
            "smartprot_policy", Context.MODE_PRIVATE
        )
        prefs.edit().putInt(PREF_POLICY_VERSION, version).apply()
    }

    companion object {
        private const val TAG = "PolicySyncWorker"
        private const val PREF_POLICY_VERSION = "current_policy_version"

        fun enqueue(context: Context, intervalMinutes: Long = 5) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<PolicySyncWorker>(
                intervalMinutes, TimeUnit.MINUTES
            )
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                "policy_sync",
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }

        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork("policy_sync")
        }
    }
}
