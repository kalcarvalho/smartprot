package com.sysfactor.apps.smartprot.ui

import android.content.ComponentName
import android.content.Context
import android.content.pm.PackageManager

/**
 * Toggles the home-screen icon by enabling/disabling the launcher alias —
 * MainActivity itself stays enabled so pairing/VPN-permission flows can
 * still launch it explicitly (am start, BootReceiver, etc).
 */
object AppVisibility {

    fun setIconVisible(context: Context, visible: Boolean) {
        val alias = ComponentName(context, "${context.packageName}.ui.MainActivityAlias")
        val state = if (visible) {
            PackageManager.COMPONENT_ENABLED_STATE_ENABLED
        } else {
            PackageManager.COMPONENT_ENABLED_STATE_DISABLED
        }
        context.packageManager.setComponentEnabledSetting(alias, state, PackageManager.DONT_KILL_APP)
    }
}
