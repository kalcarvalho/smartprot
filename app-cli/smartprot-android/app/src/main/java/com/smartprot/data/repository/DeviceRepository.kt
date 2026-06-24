package com.smartprot.data.repository

import android.content.Context
import android.os.Build
import android.provider.Settings
import com.smartprot.data.api.ApiClient
import com.smartprot.data.model.EventRequest
import com.smartprot.data.model.HeartbeatRequest
import com.smartprot.data.model.PolicyResponse
import com.smartprot.data.model.RegisterRequest
import com.smartprot.data.model.RegisterResponse

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

    fun isRegistered(): Boolean = auth.isRegistered()
    fun getDeviceId(): String? = auth.getDeviceId()
    fun getDeviceToken(): String? = auth.getDeviceToken()
    fun clearRegistration() = auth.clear()
}
