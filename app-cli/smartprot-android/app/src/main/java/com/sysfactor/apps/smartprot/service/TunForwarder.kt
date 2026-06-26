package com.sysfactor.apps.smartprot.service

import android.net.ConnectivityManager
import android.os.Build
import android.os.ParcelFileDescriptor
import android.util.Log
import java.io.FileInputStream
import java.io.FileOutputStream
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.Socket
import java.util.concurrent.ConcurrentHashMap
import kotlin.concurrent.thread
import kotlin.math.min

class TunForwarder(
    private val connectivityManager: ConnectivityManager,
    private val blockedDomains: Set<String> = emptySet(),
    private val blockedIps: Set<Int> = emptySet(),
    // Explicit exceptions to the default policy below -- always win over both
    // the blocklist and the default, matching firewall "first matching rule
    // wins" semantics (individual rules > default policy).
    private val allowedDomains: Set<String> = emptySet(),
    private val allowedIps: Set<Int> = emptySet(),
    // "allowed" (current/legacy behaviour: open unless blocked) or "blocked"
    // (deny unless explicitly allowed) -- applied only when neither an allow
    // nor a block rule matches the traffic.
    private val defaultNetwork: String = "allowed",
    private val onBlocked: ((type: String, target: String, ip: String, port: Int) -> Unit)? = null,
    // Called for every domain seen in traffic, blocked or not, so the parent panel can
    // show "domains this device is trying to reach" and let the parent block them or
    // associate them with an app dynamically. uid is the owning app's Linux uid
    // (-1 / Process.INVALID_UID if it couldn't be resolved, e.g. below API 29).
    private val onDomainObserved: ((domain: String, uid: Int) -> Unit)? = null
) {

    @Volatile private var tunIn: FileInputStream? = null
    @Volatile private var tunOut: FileOutputStream? = null
    private var readerThread: Thread? = null
    @Volatile private var running = false

    @Volatile private var currentBlockedDomains: Set<String> = blockedDomains
    @Volatile private var currentBlockedIps: Set<Int> = blockedIps
    @Volatile private var currentAllowedDomains: Set<String> = allowedDomains
    @Volatile private var currentAllowedIps: Set<Int> = allowedIps
    @Volatile private var currentDefaultNetwork: String = defaultNetwork

    private data class TcpKey(
        val srcIp: Int, val srcPort: Int, val dstIp: Int, val dstPort: Int
    )

    private class TcpConn(
        val socket: Socket,
        @Volatile var clientSeq: Long,
        @Volatile var serverSeq: Long,
        val outThread: Thread,
        val synSeq: Long
    )

    private val tcpConns = ConcurrentHashMap<TcpKey, TcpConn>()
    private val udpSocks = ConcurrentHashMap<Int, DatagramSocket>()
    private var nextUdpId = 1
    private val inspectedConns = ConcurrentHashMap<TcpKey, Boolean>()

    // Domain behind each currently-open (not yet closed) TCP connection, so
    // usage tracking can see long-lived streaming connections (e.g. a video
    // still playing) that don't re-handshake every minute and therefore never
    // reappear in the one-shot "domain observed" callback after the first tick.
    private val connDomains = ConcurrentHashMap<TcpKey, String>()

    /** Snapshot of domains with at least one currently open connection. */
    fun activeDomainsSnapshot(): Set<String> = connDomains.values.toSet()

    fun updateRules(
        domains: Set<String>,
        ips: Set<Int>,
        allowedDomains: Set<String> = emptySet(),
        allowedIps: Set<Int> = emptySet(),
        defaultNetwork: String = "allowed"
    ) {
        currentBlockedDomains = domains
        currentBlockedIps = ips
        currentAllowedDomains = allowedDomains
        currentAllowedIps = allowedIps
        currentDefaultNetwork = defaultNetwork
        Log.i(TAG, "Blocklist updated: ${domains.size} blocked domains, ${ips.size} blocked IPs, " +
            "${allowedDomains.size} allowed domains, ${allowedIps.size} allowed IPs, default=$defaultNetwork")
    }

    private fun domainMatches(domain: String, set: Set<String>): Boolean =
        set.any { entry -> domain == entry || domain.endsWith(".$entry") }

    fun start(fd: ParcelFileDescriptor) {
        tunIn = ParcelFileDescriptor.AutoCloseInputStream(fd)
        tunOut = ParcelFileDescriptor.AutoCloseOutputStream(fd)
        running = true
        readerThread = thread(isDaemon = true, name = "tun-reader") {
            Log.i(TAG, "Reader thread started, tunIn=$tunIn")
            val buf = ByteArray(65535)
            while (running) {
                try {
                    val n = tunIn?.read(buf) ?: -1
                    if (n == -1) break
                    if (n == 0) {
                        Thread.sleep(10)
                        continue
                    }
                    processPacket(buf, n)
                } catch (e: Exception) {
                    if (running) Log.e(TAG, "TUN read error", e)
                    break
                }
            }
            Log.i(TAG, "Reader thread exited")
        }
    }

    fun stop() {
        running = false
        readerThread?.interrupt()
        for ((_, conn) in tcpConns) {
            try { conn.socket.close() } catch (_: Exception) {}
            try { conn.outThread.interrupt() } catch (_: Exception) {}
        }
        tcpConns.clear()
        inspectedConns.clear()
        connDomains.clear()
        for ((_, sock) in udpSocks) {
            try { sock.close() } catch (_: Exception) {}
        }
        udpSocks.clear()
        try { tunIn?.close() } catch (_: Exception) {}
        try { tunOut?.close() } catch (_: Exception) {}
        Log.i(TAG, "TUN forwarder stopped")
    }

    private fun processPacket(buf: ByteArray, len: Int) {
        if (len < 20) return
        val version = (buf[0].toInt() shr 4) and 0x0F
        if (version != 4) return
        val ihl = (buf[0].toInt() and 0x0F) * 4
        if (ihl < 20 || len < ihl) return
        val protocol = buf[9].toInt() and 0xFF
        val srcIp = readInt(buf, 12)
        val dstIp = readInt(buf, 16)
        when (protocol) {
            6 -> handleTcp(buf, len, ihl, srcIp, dstIp)
            17 -> handleUdp(buf, len, ihl, srcIp, dstIp)
        }
    }

    private fun createSocket(): Socket {
        val sock = Socket()
        sock.tcpNoDelay = true
        connectivityManager.activeNetwork?.bindSocket(sock)
        return sock
    }

    private fun createDatagramSocket(): DatagramSocket {
        val sock = DatagramSocket()
        connectivityManager.activeNetwork?.bindSocket(sock)
        return sock
    }

    private fun handleTcp(buf: ByteArray, len: Int, ihl: Int, srcIp: Int, dstIp: Int) {
        if (len < ihl + 20) return
        val srcPort = readUShort(buf, ihl)
        val dstPort = readUShort(buf, ihl + 2)
        val seqNum = readUInt(buf, ihl + 4)
        val ackNum = readUInt(buf, ihl + 8)
        val dataOffset = ((buf[ihl + 12].toInt() shr 4) and 0x0F) * 4
        if (dataOffset < 20 || len < ihl + dataOffset) return
        val flags = buf[ihl + 13].toInt() and 0xFF
        val isSyn = (flags and 0x02) != 0
        val isFin = (flags and 0x01) != 0
        val isRst = (flags and 0x04) != 0
        val isAck = (flags and 0x10) != 0

        val key = TcpKey(srcIp, srcPort, dstIp, dstPort)
        val payloadLen = len - ihl - dataOffset

        if (isSyn && !isAck) {
            handleTcpSyn(key, dstIp, dstPort, seqNum)
            return
        }

        val conn = tcpConns[key] ?: return

        if (isRst || isFin) {
            closeTcpConn(key)
            return
        }

        if (payloadLen > 0 && isAck) {
            if (inspectedConns.put(key, true) == null) {
                if (inspectAndBlock(key, dstIp, dstPort, buf, ihl + dataOffset, payloadLen)) {
                    closeTcpConn(key)
                    return
                }
            }
            conn.clientSeq = seqNum + payloadLen
            try {
                conn.socket.getOutputStream().write(buf, ihl + dataOffset, payloadLen)
                conn.socket.getOutputStream().flush()
                // Acknowledge receipt immediately. Without this, the device's
                // TCP stack only learned its data was received whenever the
                // real server happened to send something back in the same
                // window -- connections that mostly send and rarely receive
                // (chat pings/receipts, e.g. WhatsApp) would have their send
                // window fill up waiting for an ACK that never came, and
                // silently stop sending without ever erroring or closing.
                writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                    conn.serverSeq + 1, conn.clientSeq, 0x10.toByte(), 65535, ByteArray(0), 0)
            } catch (e: Exception) {
                Log.w(TAG, "Write to ${intToIp(dstIp)}:$dstPort failed (${e.javaClass.simpleName}: ${e.message})")
                closeTcpConn(key)
            }
        }
    }

    private fun handleTcpSyn(key: TcpKey, dstIp: Int, dstPort: Int, clientSeq: Long) {
        Log.d(TAG, "SYN ${intToIp(dstIp)}:$dstPort")

        // The client may retransmit SYN if our SYN-ACK doesn't arrive in time
        // (common on flaky networks). Without this check, every retransmit
        // opened a brand-new real socket + relay thread for the same logical
        // connection, leaking both forever since the old ones were never closed.
        tcpConns[key]?.let { existing ->
            writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                existing.synSeq, clientSeq + 1, 0x12.toByte(), 65535, ByteArray(0), 0)
            return
        }

        // Firewall precedence: an explicit allow/block rule on the destination IP
        // always wins. Otherwise, ports 80/443 defer to domain-level inspection
        // once data arrives (SNI/Host), since the IP alone (e.g. a shared CDN
        // edge) isn't enough to know the domain. Any other port has no domain
        // to inspect, so the default policy applies immediately at the SYN.
        val ipAllowed = dstIp in currentAllowedIps
        val ipBlocked = dstIp in currentBlockedIps
        val blockAtSyn = when {
            ipAllowed -> false
            ipBlocked -> true
            dstPort == 80 || dstPort == 443 -> false
            else -> currentDefaultNetwork == "blocked"
        }
        if (blockAtSyn) {
            sendRst(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                clientSeq + 1, 0)
            Log.i(TAG, "Blocked TCP to IP ${intToIp(dstIp)}:$dstPort")
            onBlocked?.invoke("ip_blocked", intToIp(dstIp), intToIp(dstIp), dstPort)
            return
        }
        try {
            val sock = createSocket()
            sock.connect(InetSocketAddress(intToIp(dstIp), dstPort), 5000)
            val serverSeq = (Math.random() * ((1L shl 32) - 1)).toLong() and 0xFFFFFFFFL

            val outThread = thread(isDaemon = true, name = "tcp-out-${key.srcPort}") {
                val outBuf = ByteArray(65535)
                var localServerSeq = serverSeq
                try {
                    while (running) {
                        val n = sock.getInputStream().read(outBuf)
                        if (n <= 0) break
                        // read() can return up to the buffer size in one call, but
                        // writeTcpPacket() built exactly one IP packet with DF set
                        // and no fragmentation -- any response chunk bigger than
                        // the path MTU (~1500 bytes; a TLS Certificate message or
                        // any real HTML/JS payload routinely is) got silently
                        // dropped, so the connection looked "established" but no
                        // data ever arrived. Split into MSS-sized segments.
                        var offset = 0
                        while (offset < n) {
                            val chunkLen = min(MAX_TCP_SEGMENT_SIZE, n - offset)
                            val writeSeq = localServerSeq + 1
                            val cur = tcpConns[key]
                            val clientAck = if (cur != null) cur.clientSeq else clientSeq + 1L
                            writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                                writeSeq, clientAck,
                                0x18.toByte(), 65535, outBuf, chunkLen, offset)
                            localServerSeq += chunkLen
                            cur?.serverSeq = localServerSeq
                            offset += chunkLen
                        }
                    }
                } catch (_: Exception) {}
                closeTcpConn(key)
            }
            val conn = TcpConn(sock, clientSeq + 1, serverSeq, outThread, synSeq = serverSeq)
            tcpConns[key] = conn

            writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                serverSeq, clientSeq + 1, 0x12.toByte(), 65535, ByteArray(0), 0)
        } catch (e: Exception) {
            Log.w(TAG, "Real connect failed for ${intToIp(dstIp)}:$dstPort (${e.javaClass.simpleName}: ${e.message}) -- sending RST")
            sendRst(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                clientSeq + 1, 0)
        }
    }

    private fun inspectAndBlock(key: TcpKey, dstIp: Int, dstPort: Int, data: ByteArray, offset: Int, len: Int): Boolean {
        val domain: String? = when (dstPort) {
            80 -> extractHttpHost(data, offset, len)
            443 -> extractTlsSni(data, offset, len)
            else -> null
        }

        if (domain != null) {
            reportObservedDomain(domain, PROTO_TCP, key.srcIp, key.srcPort, dstIp, dstPort)
            connDomains[key] = domain
        }

        val ipAllowed = dstIp in currentAllowedIps
        val domainAllowed = domain != null && domainMatches(domain, currentAllowedDomains)
        if (ipAllowed || domainAllowed) return false

        val ipBlocked = dstIp in currentBlockedIps
        val domainBlocked = domain != null && domainMatches(domain, currentBlockedDomains)
        // If no domain could be extracted (e.g. unsupported TLS record) and no
        // IP-level rule matched either, this is the only inspection point for
        // the connection, so the default policy decides here.
        val shouldBlock = ipBlocked || domainBlocked || currentDefaultNetwork == "blocked"
        if (shouldBlock) {
            Log.i(TAG, "Blocked connection to ${domain ?: intToIp(dstIp)} (${intToIp(dstIp)}:$dstPort)")
            val conn = tcpConns[key]
            val rstSeq = conn?.serverSeq?.plus(1L) ?: 0L
            sendRst(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                rstSeq, 0)
            onBlocked?.invoke("domain_blocked", domain ?: intToIp(dstIp), intToIp(dstIp), dstPort)
        }
        return shouldBlock
    }

    /**
     * Resolves the owning app's uid for a connection (best-effort, API 29+) and
     * forwards the observed domain upstream regardless of block status.
     */
    private fun reportObservedDomain(domain: String, protocol: Int, srcIp: Int, srcPort: Int, dstIp: Int, dstPort: Int) {
        val callback = onDomainObserved ?: return
        val uid = resolveOwnerUid(protocol, srcIp, srcPort, dstIp, dstPort)
        callback(domain, uid)
    }

    private fun resolveOwnerUid(protocol: Int, srcIp: Int, srcPort: Int, dstIp: Int, dstPort: Int): Int {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return INVALID_UID
        return try {
            val local = InetSocketAddress(intToIp(srcIp), srcPort)
            val remote = InetSocketAddress(intToIp(dstIp), dstPort)
            connectivityManager.getConnectionOwnerUid(protocol, local, remote)
        } catch (_: Exception) {
            INVALID_UID
        }
    }

    private fun sendRst(srcIp: Int, dstIp: Int, srcPort: Int, dstPort: Int, seq: Long, ack: Long) {
        writeTcpPacket(srcIp, dstIp, srcPort, dstPort,
            seq, ack, 0x14.toByte(), 0, ByteArray(0), 0)
    }

    private fun extractHttpHost(data: ByteArray, offset: Int, len: Int): String? {
        val header = try { String(data, offset, min(len, 4096), Charsets.US_ASCII) } catch (_: Exception) { return null }
        val lines = header.split("\r\n")
        for (line in lines) {
            val trimmed = line.trimStart()
            if (trimmed.startsWith("Host:", ignoreCase = true)) {
                val host = trimmed.substring(5).trim().lowercase()
                val colonIdx = host.indexOf(':')
                return if (colonIdx >= 0) host.substring(0, colonIdx) else host
            }
        }
        return null
    }

    private fun extractTlsSni(data: ByteArray, offset: Int, len: Int): String? {
        if (len < 43) return null
        if ((data[offset].toInt() and 0xFF) != 0x16) return null
        val handshakeType = data[offset + 5].toInt() and 0xFF
        if (handshakeType != 0x01) return null
        var pos = offset + 43
        val sessionLen = if (pos + 1 <= offset + len) (data[pos].toInt() and 0xFF) else 0
        pos += 1 + sessionLen
        val cipherLen = if (pos + 1 <= offset + len) ((data[pos].toInt() and 0xFF) shl 8) or (data[pos + 1].toInt() and 0xFF) else 0
        pos += 2 + cipherLen
        val compLen = if (pos + 1 <= offset + len) (data[pos].toInt() and 0xFF) else 0
        pos += 1 + compLen
        if (pos + 4 > offset + len) return null
        val extLen = ((data[pos].toInt() and 0xFF) shl 8) or (data[pos + 1].toInt() and 0xFF)
        pos += 2
        val extEnd = pos + extLen
            while (pos + 4 <= min(extEnd, offset + len)) {
            val extType = ((data[pos].toInt() and 0xFF) shl 8) or (data[pos + 1].toInt() and 0xFF)
            val extLen2 = ((data[pos + 2].toInt() and 0xFF) shl 8) or (data[pos + 3].toInt() and 0xFF)
            pos += 4
            if (extType == 0x0000 && pos + 5 <= min(extEnd, offset + len)) {
                val sniLen = ((data[pos].toInt() and 0xFF) shl 8) or (data[pos + 1].toInt() and 0xFF)
                if (sniLen > 0 && pos + 5 <= min(extEnd, offset + len)) {
                    val nameLen = ((data[pos + 3].toInt() and 0xFF) shl 8) or (data[pos + 4].toInt() and 0xFF)
                    if (nameLen > 0 && pos + 5 + nameLen <= min(extEnd, offset + len)) {
                        return try {
                            String(data, pos + 5, nameLen, Charsets.US_ASCII).lowercase()
                        } catch (_: Exception) { null }
                    }
                }
                return null
            }
            pos += extLen2
        }
        return null
    }

    private fun closeTcpConn(key: TcpKey) {
        val conn = tcpConns.remove(key) ?: return
        inspectedConns.remove(key)
        connDomains.remove(key)
        try { conn.socket.close() } catch (_: Exception) {}
        try { conn.outThread.interrupt() } catch (_: Exception) {}
    }

    private fun handleDnsQuery(query: ByteArray, srcIp: Int, dstIp: Int, srcPort: Int, dstPort: Int): Boolean {
        if (query.size < 12) return false
        val qr = (query[2].toInt() shr 7) and 0x01
        if (qr != 0) return false
        val qdcount = readUShort(query, 4)
        if (qdcount == 0) return false
        val domain = extractDnsName(query, 12) ?: return false
        val domainLower = domain.lowercase()

        // DNS queries usually go through the device's shared system resolver, so uid
        // attribution is unreliable here — still worth reporting the domain itself.
        reportObservedDomain(domainLower, PROTO_UDP, srcIp, srcPort, dstIp, dstPort)

        if (domainMatches(domainLower, currentAllowedDomains)) return false
        val matched = domainMatches(domainLower, currentBlockedDomains) || currentDefaultNetwork == "blocked"
        if (!matched) return false
        Log.i(TAG, "Blocked DNS query for $domain")
        val resp = buildDnsNxdomain(query)
        writeUdpPacket(dstIp, srcIp, dstPort, srcPort, resp, resp.size)
        onBlocked?.invoke("dns_blocked", domainLower, intToIp(dstIp), dstPort)
        return true
    }

    private fun extractDnsName(buf: ByteArray, offset: Int): String? {
        var pos = offset
        val parts = mutableListOf<String>()
        while (pos < buf.size) {
            val len = buf[pos].toInt() and 0xFF
            if (len == 0) return parts.joinToString(".")
            if ((len and 0xC0) == 0xC0) return parts.joinToString(".")
            pos++
            if (pos + len > buf.size) return null
            parts.add(String(buf, pos, len, Charsets.UTF_8))
            pos += len
        }
        return null
    }

    private fun buildDnsNxdomain(query: ByteArray): ByteArray {
        val resp = ByteArray(min(query.size + 12, 512))
        System.arraycopy(query, 0, resp, 0, query.size)
        resp[2] = (resp[2].toInt() or 0x80).toByte()
        resp[3] = (resp[3].toInt() or 0x03).toByte()
        writeUShort(resp, 6, 0)
        writeUShort(resp, 8, 0)
        writeUShort(resp, 10, 0)
        return resp
    }

    private fun writeTcpPacket(
        srcIp: Int, dstIp: Int, srcPort: Int, dstPort: Int,
        seqNum: Long, ackNum: Long, flags: Byte, window: Int,
        data: ByteArray, dataLen: Int, dataOffset: Int = 0
    ) {
        val tcpHdrLen = 20
        val totalHdrLen = 20 + tcpHdrLen
        val totalLen = totalHdrLen + dataLen
        val pkt = ByteArray(totalLen)

        pkt[0] = 0x45.toByte()
        pkt[1] = 0x00.toByte()
        writeUShort(pkt, 2, totalLen)
        writeUShort(pkt, 4, 0)
        writeUShort(pkt, 6, 0x4000)
        pkt[8] = 64.toByte()
        pkt[9] = 6
        writeInt(pkt, 12, srcIp)
        writeInt(pkt, 16, dstIp)
        writeUShort(pkt, 10, 0)
        val ipHdrCksum = ipChecksum(pkt, 0, 20)
        writeUShort(pkt, 10, ipHdrCksum)

        writeUShort(pkt, 20, srcPort)
        writeUShort(pkt, 22, dstPort)
        writeUInt(pkt, 24, seqNum)
        writeUInt(pkt, 28, ackNum)
        pkt[30] = 0x50.toByte()
        pkt[31] = flags
        writeUShort(pkt, 32, window)
        writeUShort(pkt, 34, 0)
        writeUShort(pkt, 36, 0)

        if (dataLen > 0) {
            System.arraycopy(data, dataOffset, pkt, totalHdrLen, dataLen)
        }

        val tcpCksum = tcpChecksum(pkt, 20, tcpHdrLen + dataLen, srcIp, dstIp)
        writeUShort(pkt, 36, tcpCksum)

        try { tunOut?.write(pkt) } catch (e: Exception) {}
    }

    private fun handleUdp(buf: ByteArray, len: Int, ihl: Int, srcIp: Int, dstIp: Int) {
        if (len < ihl + 8) return
        val srcPort = readUShort(buf, ihl)
        val dstPort = readUShort(buf, ihl + 2)
        val udpLen = readUShort(buf, ihl + 4)
        if (len < ihl + udpLen) return
        val payload = buf.copyOfRange(ihl + 8, ihl + udpLen)

        if (dstPort == 53) {
            if (handleDnsQuery(payload, srcIp, dstIp, srcPort, dstPort)) return
        } else {
            val ipAllowed = dstIp in currentAllowedIps
            if (!ipAllowed) {
                // QUIC carries its own encrypted SNI we can't inspect at this layer.
                // Forcing every QUIC attempt to fail (so the client falls back to
                // TCP, where inspectAndBlock() can read the ClientHello SNI) used
                // to trigger whenever ANY domain rule existed -- but that broke
                // QUIC for every other site/app too, not just the blocked one,
                // and this relay's TCP path isn't reliable enough to be a
                // universal fallback. Only pay that price in the strict
                // default_network="blocked" mode, where breaking unidentifiable
                // encrypted traffic is the deliberate point. Otherwise, a
                // domain blocked only over QUIC/HTTP3 stays reachable -- a
                // known gap, accepted in exchange for not breaking everything.
                if (dstPort == 443 && currentDefaultNetwork == "blocked") {
                    Log.i(TAG, "Blocked UDP:443 to ${intToIp(dstIp)} (forcing TCP fallback)")
                    return
                }
                val ipBlocked = dstIp in currentBlockedIps
                if (ipBlocked || currentDefaultNetwork == "blocked") {
                    onBlocked?.invoke("ip_blocked", intToIp(dstIp), intToIp(dstIp), dstPort)
                    return
                }
            }
        }

        val udpId = dstPort % 10000 + (Math.abs(dstIp) % 255) * 10000
        var sock = udpSocks[udpId]
        if (sock == null) {
            try {
                sock = createDatagramSocket()
                udpSocks[udpId] = sock
                val finalSock = sock
                val fSrcIp = srcIp
                val fDstIp = dstIp
                val fSrcPort = srcPort
                val fDstPort = dstPort
                thread(isDaemon = true, name = "udp-out-$udpId") {
                    val rb = ByteArray(65535)
                    while (running) {
                        try {
                            val dp = DatagramPacket(rb, rb.size)
                            finalSock.soTimeout = 60000
                            finalSock.receive(dp)
                            val respPayload = rb.copyOf(dp.length)
                            writeUdpPacket(fDstIp, fSrcIp, fDstPort, fSrcPort, respPayload, dp.length)
                        } catch (_: Exception) { break }
                    }
                    try { finalSock.close() } catch (_: Exception) {}
                    udpSocks.remove(udpId)
                }
            } catch (_: Exception) { return }
        }

        try {
            val dp = DatagramPacket(payload, payload.size, InetAddress.getByAddress(intToBytes(dstIp)), dstPort)
            sock.send(dp)
        } catch (_: Exception) {}
    }

    private fun writeUdpPacket(srcIp: Int, dstIp: Int, srcPort: Int, dstPort: Int, data: ByteArray, dataLen: Int) {
        val udpHdrLen = 8
        val totalHdrLen = 20 + udpHdrLen
        val totalLen = totalHdrLen + dataLen
        val pkt = ByteArray(totalLen)

        pkt[0] = 0x45.toByte()
        pkt[1] = 0x00.toByte()
        writeUShort(pkt, 2, totalLen)
        writeUShort(pkt, 4, 0)
        writeUShort(pkt, 6, 0x4000)
        pkt[8] = 64.toByte()
        pkt[9] = 17
        writeInt(pkt, 12, srcIp)
        writeInt(pkt, 16, dstIp)
        writeUShort(pkt, 10, 0)
        val ipHdrCksum = ipChecksum(pkt, 0, 20)
        writeUShort(pkt, 10, ipHdrCksum)

        writeUShort(pkt, 20, srcPort)
        writeUShort(pkt, 22, dstPort)
        writeUShort(pkt, 24, udpHdrLen + dataLen)
        writeUShort(pkt, 26, 0)
        System.arraycopy(data, 0, pkt, totalHdrLen, dataLen)
        val udpCksum = udpChecksum(pkt, 20, udpHdrLen + dataLen, srcIp, dstIp)
        if (udpCksum != 0) writeUShort(pkt, 26, udpCksum)

        try { tunOut?.write(pkt) } catch (e: Exception) {}
    }

    private fun ipChecksum(buf: ByteArray, offset: Int, len: Int): Int {
        var sum = 0
        var i = offset
        while (i < offset + len - 1) {
            sum += readUShort(buf, i)
            i += 2
        }
        if (i < offset + len) sum += (buf[i].toInt() and 0xFF) shl 8
        sum = (sum ushr 16) + (sum and 0xFFFF)
        sum = (sum ushr 16) + (sum and 0xFFFF)
        return (sum.inv() and 0xFFFF)
    }

    private fun tcpChecksum(buf: ByteArray, offset: Int, len: Int, srcIp: Int, dstIp: Int): Int {
        var sum = 0
        sum += (srcIp ushr 16) and 0xFFFF
        sum += srcIp and 0xFFFF
        sum += (dstIp ushr 16) and 0xFFFF
        sum += dstIp and 0xFFFF
        sum += 6
        sum += len
        var i = offset
        while (i < offset + len - 1) {
            sum += readUShort(buf, i)
            i += 2
        }
        if (i < offset + len) sum += (buf[i].toInt() and 0xFF) shl 8
        sum = (sum ushr 16) + (sum and 0xFFFF)
        sum = (sum ushr 16) + (sum and 0xFFFF)
        return (sum.inv() and 0xFFFF)
    }

    private fun udpChecksum(buf: ByteArray, offset: Int, len: Int, srcIp: Int, dstIp: Int): Int {
        var sum = 0
        sum += (srcIp ushr 16) and 0xFFFF
        sum += srcIp and 0xFFFF
        sum += (dstIp ushr 16) and 0xFFFF
        sum += dstIp and 0xFFFF
        sum += 17
        sum += len
        var i = offset
        while (i < offset + len - 1) {
            sum += readUShort(buf, i)
            i += 2
        }
        if (i < offset + len) sum += (buf[i].toInt() and 0xFF) shl 8
        sum = (sum ushr 16) + (sum and 0xFFFF)
        sum = (sum ushr 16) + (sum and 0xFFFF)
        return (sum.inv() and 0xFFFF)
    }

    private fun readUShort(buf: ByteArray, offset: Int): Int =
        ((buf[offset].toInt() and 0xFF) shl 8) or (buf[offset + 1].toInt() and 0xFF)

    private fun writeUShort(buf: ByteArray, offset: Int, value: Int) {
        buf[offset] = (value ushr 8).toByte()
        buf[offset + 1] = value.toByte()
    }

    private fun readInt(buf: ByteArray, offset: Int): Int =
        ((buf[offset].toInt() and 0xFF) shl 24) or ((buf[offset + 1].toInt() and 0xFF) shl 16) or
                ((buf[offset + 2].toInt() and 0xFF) shl 8) or (buf[offset + 3].toInt() and 0xFF)

    private fun writeInt(buf: ByteArray, offset: Int, value: Int) {
        buf[offset] = (value ushr 24).toByte()
        buf[offset + 1] = (value ushr 16).toByte()
        buf[offset + 2] = (value ushr 8).toByte()
        buf[offset + 3] = value.toByte()
    }

    private fun readUInt(buf: ByteArray, offset: Int): Long =
        ((buf[offset].toLong() and 0xFF) shl 24) or ((buf[offset + 1].toLong() and 0xFF) shl 16) or
                ((buf[offset + 2].toLong() and 0xFF) shl 8) or (buf[offset + 3].toLong() and 0xFF)

    private fun writeUInt(buf: ByteArray, offset: Int, value: Long) {
        buf[offset] = (value ushr 24).toByte()
        buf[offset + 1] = (value ushr 16).toByte()
        buf[offset + 2] = (value ushr 8).toByte()
        buf[offset + 3] = value.toByte()
    }

    private fun intToIp(v: Int): String =
        "${(v ushr 24) and 0xFF}.${(v ushr 16) and 0xFF}.${(v ushr 8) and 0xFF}.${v and 0xFF}"

    private fun intToBytes(v: Int): ByteArray = byteArrayOf(
        (v ushr 24).toByte(),
        (v ushr 16).toByte(),
        (v ushr 8).toByte(),
        v.toByte()
    )

    companion object {
        private const val TAG = "TunForwarder"
        private const val PROTO_TCP = 6
        private const val PROTO_UDP = 17
        private const val INVALID_UID = -1
        // Conservative MSS: well under any real-world path MTU (cellular links
        // can be smaller than Ethernet's 1500) plus 40 bytes of IPv4+TCP header.
        private const val MAX_TCP_SEGMENT_SIZE = 1400
    }
}
