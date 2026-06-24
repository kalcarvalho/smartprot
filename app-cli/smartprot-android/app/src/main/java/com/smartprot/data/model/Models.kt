package com.smartprot.data.model

import com.google.gson.annotations.SerializedName

data class RegisterRequest(
    val name: String,
    val platform: String,
    @SerializedName("device_fingerprint") val deviceFingerprint: String
)

data class RegisterResponse(
    @SerializedName("device_id") val deviceId: String,
    val token: String,
    @SerializedName("policy_version") val policyVersion: Int
)

data class HeartbeatRequest(
    @SerializedName("policy_version") val policyVersion: Int?,
    @SerializedName("battery_percent") val batteryPercent: Int?,
    @SerializedName("vpn_active") val vpnActive: Boolean?
)

data class HeartbeatResponse(
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("server_time") val serverTime: String
)

data class PolicyResponse(
    @SerializedName("device_id") val deviceId: String,
    val version: Int,
    val rules: List<Rule>
)

data class Rule(
    val type: String,
    val target: String,
    val network: String,
    val from: String? = null,
    val until: String? = null
)

data class EventRequest(
    val type: String,
    val payload: Map<String, Any?>?,
    @SerializedName("occurred_at") val occurredAt: String? = null
)

data class EventResponse(
    val accepted: Boolean
)
