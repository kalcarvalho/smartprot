package com.sysfactor.apps.smartprot.data.repository

import android.content.Context
import android.os.Build
import android.provider.Settings
import com.sysfactor.apps.smartprot.data.api.ApiClient
import com.sysfactor.apps.smartprot.data.model.DomainsRequest
import com.sysfactor.apps.smartprot.data.model.EventRequest
import com.sysfactor.apps.smartprot.data.model.HeartbeatRequest
import com.sysfactor.apps.smartprot.data.model.PolicyResponse
import com.sysfactor.apps.smartprot.data.model.RegisterRequest
import com.sysfactor.apps.smartprot.data.model.RegisterResponse
import com.sysfactor.apps.smartprot.data.model.UsageRequest

class DeviceRepository(context: Context) {

    private val auth = ApiClient.getAuthInterceptor()
    private val api = ApiClient.getApi()
    private val fingerprint = Build.ID + "|" + Settings.Secure.getString(
        context.contentResolver, Settings.Secure.ANDROID_ID
    )

    suspend fun register(name: String): Result<RegisterResponse> {
        return try {
            val request = RegisterRequest(
                name = name,
                platform = "android",
                deviceFingerprint = fingerprint
            )
            val response = api.register(request)
            auth.saveDeviceToken(response.token)
            auth.saveDeviceId(response.deviceId)
            Result.success(response)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun sendHeartbeat(policyVersion: Int?, battery: Int?, vpnActive: Boolean?): Result<Unit> {
        return try {
            val deviceId = auth.getDeviceId() ?: return Result.failure(Exception("Not registered"))
            val request = HeartbeatRequest(
                policyVersion = policyVersion,
                batteryPercent = battery,
                vpnActive = vpnActive
            )
            api.heartbeat(deviceId, request)
            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun syncPolicy(): Result<PolicyResponse> {
        return try {
            val deviceId = auth.getDeviceId() ?: return Result.failure(Exception("Not registered"))
            val policy = api.getPolicy(deviceId)
            Result.success(policy)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun reportEvent(type: String, payload: Map<String, Any?>?) {
        try {
            val deviceId = auth.getDeviceId() ?: return
            val request = EventRequest(type = type, payload = payload)
            api.storeEvent(deviceId, request)
        } catch (_: Exception) {
        }
    }

    /**
     * Reports domains observed on the device's traffic so the parent panel can
     * surface them for blocking or association with an app. [appPackage] groups
     * every domain in this call under the same app (or null if it couldn't be
     * attributed to a specific app, e.g. domains seen via DNS only).
     */
    suspend fun reportDomains(domains: List<String>, appPackage: String?) {
        if (domains.isEmpty()) return
        try {
            val deviceId = auth.getDeviceId() ?: return
            val request = DomainsRequest(domains = domains, appPackage = appPackage)
            api.storeDomains(deviceId, request)
        } catch (_: Exception) {
        }
    }

    /** Reports today's accumulated usage minutes per rule id, so the panel can show "used X / Y min today". */
    suspend fun reportUsage(usage: Map<String, Int>) {
        if (usage.isEmpty()) return
        try {
            val deviceId = auth.getDeviceId() ?: return
            api.storeUsage(deviceId, UsageRequest(usage = usage))
        } catch (_: Exception) {
        }
    }

    fun isRegistered(): Boolean = auth.isRegistered()
    fun getDeviceId(): String? = auth.getDeviceId()
    fun getDeviceToken(): String? = auth.getDeviceToken()
    fun clearRegistration() = auth.clear()
}
