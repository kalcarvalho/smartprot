package com.sysfactor.apps.smartprot.data.api

import com.sysfactor.apps.smartprot.data.model.DomainsRequest
import com.sysfactor.apps.smartprot.data.model.DomainsResponse
import com.sysfactor.apps.smartprot.data.model.EventRequest
import com.sysfactor.apps.smartprot.data.model.EventResponse
import com.sysfactor.apps.smartprot.data.model.HeartbeatRequest
import com.sysfactor.apps.smartprot.data.model.HeartbeatResponse
import com.sysfactor.apps.smartprot.data.model.PolicyResponse
import com.sysfactor.apps.smartprot.data.model.RegisterRequest
import com.sysfactor.apps.smartprot.data.model.RegisterResponse
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

interface DeviceApi {

    @POST("devices/register")
    suspend fun register(@Body request: RegisterRequest): RegisterResponse

    @POST("devices/{deviceId}/heartbeat")
    suspend fun heartbeat(
        @Path("deviceId") deviceId: String,
        @Body request: HeartbeatRequest
    ): HeartbeatResponse

    @GET("devices/{deviceId}/policy")
    suspend fun getPolicy(@Path("deviceId") deviceId: String): PolicyResponse

    @POST("devices/{deviceId}/events")
    suspend fun storeEvent(
        @Path("deviceId") deviceId: String,
        @Body request: EventRequest
    ): EventResponse

    @POST("devices/{deviceId}/domains")
    suspend fun storeDomains(
        @Path("deviceId") deviceId: String,
        @Body request: DomainsRequest
    ): DomainsResponse
}
