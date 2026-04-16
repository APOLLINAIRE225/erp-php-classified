package cloud.coredesk.esperance.messaging.monitor

import android.net.Uri
import android.util.Log
import android.webkit.CookieManager
import cloud.coredesk.esperance.messaging.BuildConfig
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.Executors

object AppEventReporter {
    private const val TAG = "AppEventReporter"
    private val client = OkHttpClient()
    private val executor = Executors.newSingleThreadExecutor()

    fun enqueue(type: String, message: String, url: String?, details: String? = null, source: String = "android_wrapper") {
        if (message.isBlank()) return

        executor.execute {
            runCatching {
                val cookieHeader = resolveCookieHeader(url)
                val body = FormBody.Builder()
                    .add("type", type)
                    .add("message", message.take(500))
                    .add("url", (url ?: BuildConfig.BASE_URL).take(500))
                    .add("details", (details ?: "").take(2000))
                    .add("source", source.take(120))
                    .build()

                val builder = Request.Builder()
                    .url(BuildConfig.REPORT_EVENT_URL)
                    .post(body)

                if (!cookieHeader.isNullOrBlank()) {
                    builder.header("Cookie", cookieHeader)
                }

                client.newCall(builder.build()).execute().use { response ->
                    if (!response.isSuccessful) {
                        Log.e(TAG, "App event report failed: HTTP ${response.code}")
                    }
                }
            }.onFailure { error ->
                Log.e(TAG, "App event report error", error)
            }
        }
    }

    private fun resolveCookieHeader(cookieUrl: String?): String? {
        val manager = CookieManager.getInstance()
        val candidates = linkedSetOf<String>()
        if (!cookieUrl.isNullOrBlank()) {
            candidates += cookieUrl
            runCatching {
                val uri = Uri.parse(cookieUrl)
                val origin = buildString {
                    append(uri.scheme ?: "https")
                    append("://")
                    append(uri.host ?: return@runCatching)
                    if (uri.port != -1) {
                        append(":")
                        append(uri.port)
                    }
                }
                candidates += origin
            }
        }
        candidates += BuildConfig.BASE_URL

        for (candidate in candidates) {
            val cookie = manager.getCookie(candidate)
            if (!cookie.isNullOrBlank()) {
                return cookie
            }
        }
        return null
    }
}
