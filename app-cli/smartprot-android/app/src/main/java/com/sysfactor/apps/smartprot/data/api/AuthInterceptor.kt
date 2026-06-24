package com.sysfactor.apps.smartprot.data.api

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import okhttp3.Interceptor
import okhttp3.Response
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class AuthInterceptor(context: Context) : Interceptor {

    private val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    override fun intercept(chain: Interceptor.Chain): Response {
        val token = getDeviceToken()
        val request = if (token != null) {
            chain.request().newBuilder()
                .addHeader("Authorization", "Bearer $token")
                .build()
        } else {
            chain.request()
        }
        return chain.proceed(request)
    }

    fun saveDeviceToken(token: String) {
        prefs.edit().putString(KEY_DEVICE_TOKEN, token).apply()
    }

    fun getDeviceToken(): String? {
        return prefs.getString(KEY_DEVICE_TOKEN, null)
    }

    fun saveDeviceId(id: String) {
        prefs.edit().putString(KEY_DEVICE_ID, id).apply()
    }

    fun getDeviceId(): String? {
        return prefs.getString(KEY_DEVICE_ID, null)
    }

    fun isRegistered(): Boolean {
        return getDeviceToken() != null && getDeviceId() != null
    }

    fun clear() {
        prefs.edit()
            .remove(KEY_DEVICE_TOKEN)
            .remove(KEY_DEVICE_ID)
            .apply()
    }

    companion object {
        private const val PREFS_NAME = "smartprot_auth"
        private const val KEY_DEVICE_TOKEN = "device_token"
        private const val KEY_DEVICE_ID = "device_id"
    }
}
