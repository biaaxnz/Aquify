"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import {
  getNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  notificationChangeEventName,
  type AquifyNotification,
} from "@/lib/notification-store";

export function NotificationCenter() {
  const [notifications, setNotifications] = useState<AquifyNotification[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const reload = () => {
      try {
        setNotifications(getNotifications());
        setError(null);
      } catch (cause) {
        console.error("Gagal membaca riwayat notifikasi Aquify.", cause);
        setError("Riwayat notifikasi tidak dapat dibaca di perangkat ini.");
      }
    };
    reload();
    window.addEventListener(notificationChangeEventName(), reload);
    return () => window.removeEventListener(notificationChangeEventName(), reload);
  }, []);

  const unreadCount = notifications.filter((notification) => !notification.readAt).length;
  const markRead = (id: string) => {
    try {
      markNotificationRead(id);
    } catch (cause) {
      console.error("Gagal memperbarui status notifikasi.", cause);
      setError("Status notifikasi gagal diperbarui.");
    }
  };
  const markAllRead = () => {
    try {
      markAllNotificationsRead();
    } catch (cause) {
      console.error("Gagal memperbarui semua notifikasi.", cause);
      setError("Status notifikasi gagal diperbarui.");
    }
  };

  return (
    <section className="notification-center">
      <div className="notification-summary">
        <b>{unreadCount} belum dibaca dari {notifications.length} notifikasi</b>
        {unreadCount > 0 && <button className="btn outline sm" type="button" onClick={markAllRead}>Tandai semua dibaca</button>}
      </div>
      {error && <div className="alert bad" role="alert">{error}</div>}
      {notifications.length === 0 ? (
        <div className="empty"><b>Belum ada notifikasi</b>Peringatan skrining kualitas air dan pemberitahuan unduhan PDF akan muncul di sini pada perangkat ini.</div>
      ) : (
        <div className="notification-list">
          {notifications.map((notification) => (
            <article className={`notification-item${notification.readAt ? " is-read" : " is-unread"}`} key={notification.id}>
              <div className="notification-item-main">
                <span className="notification-status" aria-label={notification.readAt ? "Sudah dibaca" : "Belum dibaca"} />
                <div>
                  <b>{notification.title}</b>
                  <p>{notification.message}</p>
                  <small>{new Date(notification.createdAt).toLocaleString("id-ID")}</small>
                  <div className="notification-actions">
                    <Link className="tx-link" href={notification.href} onClick={() => markRead(notification.id)}>Buka detail</Link>
                    {!notification.readAt && <button className="tx-link notification-read" type="button" onClick={() => markRead(notification.id)}>Tandai dilihat</button>}
                  </div>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
