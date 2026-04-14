package cloud.coredesk.esperance.messaging

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build

class MessagingApp : Application() {
    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val channel = NotificationChannel(
            BuildConfig.PUSH_CHANNEL_ID,
            BuildConfig.PUSH_CHANNEL_NAME,
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Alertes messages avec priorité haute"
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 300, 150, 300, 150, 500)
            setShowBadge(true)
            lockscreenVisibility = android.app.Notification.VISIBILITY_PUBLIC
        }

        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(channel)
    }
}
