const CACHE='my-store-static-v1';
const ASSETS=['/assets/app.css','/assets/app.js','/assets/bootstrap.min.css','/assets/icon.svg'];
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS))));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key))))));
self.addEventListener('fetch',event=>{const url=new URL(event.request.url);if(event.request.method!=='GET'||url.origin!==self.location.origin||!ASSETS.includes(url.pathname)||url.search)return;event.respondWith(caches.match(event.request).then(cached=>cached||fetch(event.request)));});
