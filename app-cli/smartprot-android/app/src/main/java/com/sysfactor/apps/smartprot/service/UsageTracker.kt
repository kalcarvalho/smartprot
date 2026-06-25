package com.sysfactor.apps.smartprot.service

import android.content.Context
import java.time.LocalDate

/**
 * Tracks accumulated minutes of usage per rule, reset at local midnight.
 * Ticked roughly once a minute by whoever observes that a rule's app/domain
 * had traffic in that window -- approximate by nature (minute-granularity,
 * only while the VPN foreground service is alive), same accuracy tier as the
 * rest of this app's background scheduling.
 */
class UsageTracker(context: Context) {

    private val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun recordTick(ruleId: String, minutes: Int = 1) {
        resetIfNewDay()
        val key = usageKey(ruleId)
        val current = prefs.getInt(key, 0)
        prefs.edit().putInt(key, current + minutes).apply()
    }

    fun minutesUsedToday(ruleId: String): Int {
        resetIfNewDay()
        return prefs.getInt(usageKey(ruleId), 0)
    }

    private fun usageKey(ruleId: String) = "usage_$ruleId"

    private fun resetIfNewDay() {
        val today = LocalDate.now().toString()
        val storedDay = prefs.getString(KEY_DAY, null)
        if (storedDay != today) {
            prefs.edit().clear().putString(KEY_DAY, today).apply()
        }
    }

    companion object {
        private const val PREFS_NAME = "smartprot_usage"
        private const val KEY_DAY = "current_day"
    }
}
