package com.smartprot.service

import android.net.ConnectivityManager
import android.net.Network
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
    private val network: Network? = null,
    private val blockedDomains: Set<String> = emptySet(),
    private val blockedIps: Set<Int> = emptySet()
) {

    private var tunIn: FileInputStream? = null
    private var tunOut: FileOutputStream? = null
    private var readerThread: Thread? = null
    private var running = false

    @Volatile private var currentBlockedDomains: Set<String> = blockedDomains
    @Volatile private var currentBlockedIps: Set<Int> = blockedIps

    private data class TcpKey(
        val srcIp: Int, val srcPort: Int, val dstIp: Int, val dstPort: Int
    )

    private class TcpConn(
        val socket: Socket,
        @Volatile var clientSeq: Long,
        @Volatile var serverSeq: Long,
        val outThread: Thread
    )

    private val tcpConns = ConcurrentHashMap<TcpKey, TcpConn>()
    private val udpSocks = ConcurrentHashMap<Int, DatagramSocket>()
    private var nextUdpId = 1
    private val inspectedConns = ConcurrentHashMap<TcpKey, Boolean>()

    fun updateRules(domains: Set<String>, ips: Set<Int>) {
        currentBlockedDomains = domains
        currentBlockedIps = ips
        Log.i(TAG, "Blocklist updated: ${domains.size} domains, ${ips.size} IPs")
    }

    fun start(fd: ParcelFileDescriptor) {
        tunIn = FileInputStream(fd.fileDescriptor)
        tunOut = FileOutputStream(fd.fileDescriptor)
        running = true
        readerThread = thread(isDaemon = true, name = "tun-reader") {
            val buf = ByteArray(65535)
            while (running) {
                try {
                    val n = tunIn?.read(buf) ?: break
                    if (n <= 0) break
                    processPacket(buf, n)
                } catch (e: Exception) {
                    if (running) Log.e(TAG, "TUN read error", e)
                    break
                }
            }
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
        network?.bindSocket(sock)
        return sock
    }

    private fun createDatagramSocket(): DatagramSocket {
        val sock = DatagramSocket()
        network?.bindSocket(sock)
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
            } catch (e: Exception) {
                closeTcpConn(key)
            }
        }
    }

    private fun handleTcpSyn(key: TcpKey, dstIp: Int, dstPort: Int, clientSeq: Long) {
        Log.d(TAG, "SYN ${intToIp(dstIp)}:$dstPort")
        if (dstIp in currentBlockedIps) {
            writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                0, 0, 0x14.toByte(), 0, ByteArray(0), 0)
            Log.i(TAG, "Blocked TCP to IP ${intToIp(dstIp)}:$dstPort")
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
                        val writeSeq = localServerSeq + 1
                        val cur = tcpConns[key]
                        val clientAck = cur?.clientSeq ?: (clientSeq + 1)
                        writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                            writeSeq, clientAck,
                            0x18.toByte(), 65535, outBuf, n)
                        localServerSeq += n
                        cur?.serverSeq = localServerSeq
                    }
                } catch (_: Exception) {}
                closeTcpConn(key)
            }
            val conn = TcpConn(sock, clientSeq, serverSeq, outThread)
            tcpConns[key] = conn
            conn.outThread.start()

            writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                serverSeq, clientSeq + 1, 0x12.toByte(), 65535, ByteArray(0), 0)
        } catch (e: Exception) {
            writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                0, 0, 0x14.toByte(), 0, ByteArray(0), 0)
        }
    }

    private fun inspectAndBlock(key: TcpKey, dstIp: Int, dstPort: Int, data: ByteArray, offset: Int, len: Int): Boolean {
        if (currentBlockedDomains.isEmpty()) return false
        val domain: String? = when (dstPort) {
            80 -> extractHttpHost(data, offset, len)
            443 -> extractTlsSni(data, offset, len)
            else -> null
        }
        val matched = domain?.let { d ->
            currentBlockedDomains.any { blocked ->
                d == blocked || d.endsWith(".$blocked")
            }
        } ?: false
        if (matched) {
            Log.i(TAG, "Blocked connection to $domain (${intToIp(dstIp)}:$dstPort)")
            writeTcpPacket(key.dstIp, key.srcIp, key.dstPort, key.srcPort,
                0, 0, 0x14.toByte(), 0, ByteArray(0), 0)
        }
        return matched
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
        val matched = currentBlockedDomains.any { blocked ->
            domainLower == blocked || domainLower.endsWith(".$blocked")
        }
        if (!matched) return false
        Log.i(TAG, "Blocked DNS query for $domain")
        val resp = buildDnsNxdomain(query)
        writeUdpPacket(dstIp, srcIp, dstPort, srcPort, resp, resp.size)
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
        data: ByteArray, dataLen: Int
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
            System.arraycopy(data, 0, pkt, totalHdrLen, dataLen)
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

        if (dstPort == 53 && currentBlockedDomains.isNotEmpty()) {
            if (handleDnsQuery(payload, srcIp, dstIp, srcPort, dstPort)) return
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
    }
}
