package cloud.coredesk.esperance.messaging.push

import android.content.Context
import android.webkit.CookieManager
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.Executors
import cloud.coredesk.esperance.messaging.BuildConfig

object TokenSync {
    private val client = OkHttpClient()
    private val executor = Executors.newSingleThreadExecutor()

    fun enqueue(context: Context, token: String) {
        executor.execute {
            runCatching {
                val cookieHeader = CookieManager.getInstance().getCookie(BuildConfig.BASE_URL)
                val body = FormBody.Builder()
                    .add("token", token)
                    .add("platform", "android_native")
                    .build()
                val builder = Request.Builder()
                    .url(BuildConfig.REGISTER_TOKEN_URL)
                    .post(body)
                if (!cookieHeader.isNullOrBlank()) {
                    builder.header("Cookie", cookieHeader)
                }
                val request = builder.build()
                client.newCall(request).execute().close()
            }
        }
    }
}
