package com.sysfactor.apps.smartprot.data.model

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
    val rules: List<Rule>,
    @SerializedName("app_domains") val appDomains: Map<String, List<String>>? = null,
    val settings: PolicySettings? = null
)

data class PolicySettings(
    @SerializedName("protection_enabled") val protectionEnabled: Boolean = true,
    @SerializedName("app_icon_visible") val appIconVisible: Boolean = true,
    @SerializedName("default_network") val defaultNetwork: String = "allowed"
)

data class Rule(
    val id: String? = null,
    val type: String,
    val target: String,
    val network: String,
    val from: String? = null,
    val until: String? = null,
    val schedule: Schedule? = null,
    @SerializedName("daily_limit_minutes") val dailyLimitMinutes: Int? = null
)

data class Schedule(
    val days: List<String>? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null
)

data class EventRequest(
    val type: String,
    val payload: Map<String, Any?>?,
    @SerializedName("occurred_at") val occurredAt: String? = null
)

data class EventResponse(
    val accepted: Boolean
)

data class DomainsRequest(
    val domains: List<String>,
    @SerializedName("app_package") val appPackage: String? = null
)

data class DomainsResponse(
    val accepted: Boolean,
    val inserted: Int
)
