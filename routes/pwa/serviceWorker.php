<?php
declare(strict_types=1);

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

header_remove('Content-Type');
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Service-Worker-Allowed: /');
header('X-Content-Type-Options: nosniff');

?>
const CACHE_PREFIX = "smartbooks-pwa";
const CACHE_VERSION = "v3-api-hosted";
const STATIC_CACHE = `${CACHE_PREFIX}-static-${CACHE_VERSION}`;
const APP_BASE_URL = new URL("/", self.location.origin);

const appUrl = (path = "") => new URL(path, APP_BASE_URL).toString();
const PRECACHE_URLS = [appUrl()];

const cacheResponse = async (cache, key, response) => {
  if (!response || !response.ok || response.type !== "basic") return;
  const cacheControl = response.headers.get("Cache-Control") || "";
  if (/no-store|private/i.test(cacheControl)) return;
  await cache.put(key, response.clone());
};

self.addEventListener("install", (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      await Promise.all(
        PRECACHE_URLS.map(async (url) => {
          try {
            const response = await fetch(url, { credentials: "same-origin" });
            await cacheResponse(cache, url, response);
          } catch (_) {}
        })
      );
      await self.skipWaiting();
    })()
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      const cacheNames = await caches.keys();
      await Promise.all(
        cacheNames
          .filter((name) => name.startsWith(`${CACHE_PREFIX}-`) && name !== STATIC_CACHE)
          .map((name) => caches.delete(name))
      );
      await self.clients.claim();
    })()
  );
});

const isApiOrSensitiveRequest = (request, url) => {
  const accept = request.headers.get("Accept") || "";
  return (
    url.pathname.includes("/api/") ||
    url.pathname.includes("/smartbooks-server/") ||
    url.pathname.endsWith(".php") ||
    accept.includes("application/json") ||
    request.headers.has("Authorization") ||
    request.headers.has("Range")
  );
};

const isStaticAsset = (request, url) =>
  ["script", "style", "font", "image", "worker"].includes(request.destination) ||
  url.pathname.includes("/assets/") ||
  url.pathname.includes("/icons/");

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || isApiOrSensitiveRequest(request, url)) return;

  if (request.mode === "navigate") {
    event.respondWith(
      (async () => {
        try {
          return await fetch(request);
        } catch (_) {
          return (await caches.match(appUrl())) || Response.error();
        }
      })()
    );
    return;
  }

  if (!isStaticAsset(request, url)) return;
  event.respondWith(
    (async () => {
      const cached = await caches.match(request);
      if (cached) return cached;
      const response = await fetch(request);
      const cache = await caches.open(STATIC_CACHE);
      await cacheResponse(cache, request, response);
      return response;
    })()
  );
});
