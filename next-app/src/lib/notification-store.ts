"use client";

export type AquifyNotification = {
  id: string;
  dedupeKey?: string;
  title: string;
  message: string;
  href: string;
  createdAt: string;
  readAt: string | null;
};

const STORAGE_KEY = "aquify:notifications:v1";
const CHANGE_EVENT = "aquify:notifications-changed";

export function getNotifications(): AquifyNotification[] {
  const raw = window.localStorage.getItem(STORAGE_KEY);
  if (!raw) return [];
  const parsed: unknown = JSON.parse(raw);
  if (!Array.isArray(parsed)) throw new Error("Format riwayat notifikasi tidak valid.");
  return parsed as AquifyNotification[];
}

function saveNotifications(notifications: AquifyNotification[]) {
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(notifications.slice(0, 100)));
  window.dispatchEvent(new Event(CHANGE_EVENT));
}

export function addNotification(notification: Omit<AquifyNotification, "id" | "createdAt" | "readAt">) {
  const notifications = getNotifications();
  notifications.unshift({
    ...notification,
    id: crypto.randomUUID(),
    createdAt: new Date().toISOString(),
    readAt: null,
  });
  saveNotifications(notifications);
}

export function addNotificationOnce(
  dedupeKey: string,
  notification: Omit<AquifyNotification, "id" | "dedupeKey" | "createdAt" | "readAt">,
) {
  const notifications = getNotifications();
  if (notifications.some((item) => item.dedupeKey === dedupeKey)) return;
  notifications.unshift({
    ...notification,
    dedupeKey,
    id: crypto.randomUUID(),
    createdAt: new Date().toISOString(),
    readAt: null,
  });
  saveNotifications(notifications);
}

export function markNotificationRead(id: string) {
  const notifications = getNotifications();
  saveNotifications(notifications.map((notification) =>
    notification.id === id && !notification.readAt
      ? { ...notification, readAt: new Date().toISOString() }
      : notification,
  ));
}

export function markAllNotificationsRead() {
  const readAt = new Date().toISOString();
  saveNotifications(getNotifications().map((notification) => ({
    ...notification,
    readAt: notification.readAt ?? readAt,
  })));
}

export function notificationChangeEventName() {
  return CHANGE_EVENT;
}
