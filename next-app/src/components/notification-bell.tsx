"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Icon } from "@/components/icon";
import { getNotifications, notificationChangeEventName } from "@/lib/notification-store";

export function NotificationBell() {
  const [unreadCount, setUnreadCount] = useState(0);

  useEffect(() => {
    const updateCount = () => {
      try {
        setUnreadCount(getNotifications().filter((notification) => !notification.readAt).length);
      } catch (error) {
        console.error("Gagal membaca notifikasi Aquify.", error);
      }
    };
    updateCount();
    window.addEventListener(notificationChangeEventName(), updateCount);
    return () => window.removeEventListener(notificationChangeEventName(), updateCount);
  }, []);

  return (
    <Link className="iconbtn notification-bell" href="/notifications" aria-label={`Notifikasi, ${unreadCount} belum dibaca`}>
      <Icon name="bell" />
      {unreadCount > 0 && <span className="notification-count">{unreadCount > 99 ? "99+" : unreadCount}</span>}
    </Link>
  );
}
