package com.sysfactor.apps.smartprot.data.api

import android.content.Context
import com.sysfactor.apps.smartprot.BuildConfig
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object ApiClient {

    private var authInterceptor: AuthInterceptor? = null
    private var retrofit: Retrofit? = null
    private var api: DeviceApi? = null

    fun init(context: Context) {
        if (retrofit != null) return

        val interceptor = AuthInterceptor(context.applicationContext)
        authInterceptor = interceptor

        val logging = HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG)
                HttpLoggingInterceptor.Level.BODY
            else
                HttpLoggingInterceptor.Level.NONE
        }

        val okHttp = OkHttpClient.Builder()
            .addInterceptor(interceptor)
            .addInterceptor(logging)
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(15, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)
            .build()

        retrofit = Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(okHttp)
            .addConverterFactory(GsonConverterFactory.create())
            .build()

        api = retrofit!!.create(DeviceApi::class.java)
    }

    fun getAuthInterceptor(): AuthInterceptor {
        return authInterceptor ?: throw IllegalStateException("ApiClient not initialized")
    }

    fun getApi(): DeviceApi {
        return api ?: throw IllegalStateException("ApiClient not initialized")
    }
}
