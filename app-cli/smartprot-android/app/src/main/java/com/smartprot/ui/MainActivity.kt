package com.smartprot.ui

import android.app.AlertDialog
import android.content.Intent
import android.net.VpnService
import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import com.google.android.material.switchmaterial.SwitchMaterial
import com.google.gson.Gson
import com.smartprot.R
import com.smartprot.data.repository.DeviceRepository
import com.smartprot.service.PolicyVpnService
import com.smartprot.worker.HeartbeatWorker
import com.smartprot.worker.PolicySyncWorker
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class MainActivity : AppCompatActivity() {

    private lateinit var repo: DeviceRepository

    private val vpnPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == RESULT_OK) {
            startVpnService()
        }
        finishAfterTransition()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        repo = DeviceRepository(this)

        if (!repo.isRegistered()) {
            setTheme(R.style.Theme_SmartProt)
        }

        super.onCreate(savedInstanceState)

        if (repo.isRegistered()) {
            startBackgroundWorkers()
            if (!PolicyVpnService.isRunning.value) {
                requestVpnPermission()
            }
            GlobalScope.launch {
                repo.syncPolicy().fold(
                    onSuccess = { policy ->
                        if (!PolicyVpnService.isRunning.value) return@fold
                        val rulesJson = Gson().toJson(policy.rules)
                        val intent = Intent(this@MainActivity, PolicyVpnService::class.java).apply {
                            action = PolicyVpnService.ACTION_UPDATE_RULES
                            putExtra(PolicyVpnService.EXTRA_RULES_JSON, rulesJson)
                        }
                        withContext(Dispatchers.Main) {
                            startService(intent)
                        }
                    },
                    onFailure = { /* workers will retry */ }
                )
            }
            finishAfterTransition()
        } else {
            setContentView(R.layout.activity_main)
            showPairingForm()
        }
    }

    private fun showPairingForm() {
        findViewById<Button>(R.id.btn_register)?.setOnClickListener {
            val name = findViewById<EditText>(R.id.et_device_name)?.text.toString()
            if (name.isBlank()) {
                Toast.makeText(this, R.string.error_name_required, Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            lifecycleScope.launch {
                findViewById<Button>(R.id.btn_register)?.isEnabled = false
                val result = repo.register(name)
                result.fold(
                    onSuccess = { response ->
                        Toast.makeText(
                            this@MainActivity,
                            getString(R.string.registered_success, response.token),
                            Toast.LENGTH_LONG
                        ).show()
                        repo.sendHeartbeat(null, null, null)
                        showDashboard()
                        startBackgroundWorkers()
                    },
                    onFailure = { error ->
                        Toast.makeText(
                            this@MainActivity,
                            getString(R.string.registration_failed, error.message),
                            Toast.LENGTH_LONG
                        ).show()
                        findViewById<Button>(R.id.btn_register)?.isEnabled = true
                    }
                )
            }
        }
    }

    private fun showDashboard() {
        setContentView(R.layout.activity_dashboard)

        findViewById<TextView>(R.id.tv_device_id)?.text =
            getString(R.string.device_id_label, repo.getDeviceId() ?: "—")

        val vpnToggle = findViewById<SwitchMaterial>(R.id.sw_vpn_toggle)
        lifecycleScope.launch {
            lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED) {
                PolicyVpnService.isRunning.collect { running ->
                    vpnToggle.isChecked = running
                }
            }
        }

        vpnToggle.setOnCheckedChangeListener { _, isChecked ->
            if (isChecked) {
                requestVpnPermission()
            } else {
                stopVpnService()
            }
        }

        findViewById<Button>(R.id.btn_sync_policy)?.setOnClickListener {
            lifecycleScope.launch {
                repo.syncPolicy().fold(
                    onSuccess = { policy ->
                        if (PolicyVpnService.isRunning.value) {
                            val rulesJson = com.google.gson.Gson().toJson(policy.rules)
                            val intent = Intent(this@MainActivity, PolicyVpnService::class.java).apply {
                                action = PolicyVpnService.ACTION_UPDATE_RULES
                                putExtra(PolicyVpnService.EXTRA_RULES_JSON, rulesJson)
                            }
                            startService(intent)
                        }
                        Toast.makeText(this@MainActivity, R.string.policy_synced, Toast.LENGTH_SHORT).show()
                    },
                    onFailure = {
                        Toast.makeText(this@MainActivity, R.string.policy_sync_failed, Toast.LENGTH_SHORT).show()
                    }
                )
            }
        }

        findViewById<Button>(R.id.btn_unregister)?.setOnClickListener {
            AlertDialog.Builder(this)
                .setTitle(R.string.unregister_title)
                .setMessage(R.string.unregister_message)
                .setPositiveButton(R.string.yes) { _, _ ->
                    repo.clearRegistration()
                    HeartbeatWorker.cancel(this)
                    PolicySyncWorker.cancel(this)
                    stopVpnService()
                    showPairingForm()
                }
                .setNegativeButton(R.string.no, null)
                .show()
        }
    }

    private fun requestVpnPermission() {
        val intent = VpnService.prepare(this)
        if (intent != null) {
            vpnPermissionLauncher.launch(intent)
        } else {
            startVpnService()
        }
    }

    private fun startVpnService() {
        val intent = Intent(this, PolicyVpnService::class.java).apply {
            action = PolicyVpnService.ACTION_START
        }
        startForegroundService(intent)
    }

    private fun stopVpnService() {
        val intent = Intent(this, PolicyVpnService::class.java).apply {
            action = PolicyVpnService.ACTION_STOP
        }
        startService(intent)
    }

    private fun startBackgroundWorkers() {
        HeartbeatWorker.enqueue(this)
        PolicySyncWorker.enqueue(this)
    }
}
