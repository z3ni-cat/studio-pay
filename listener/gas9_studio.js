// gas9_studio.js v3 — AutoJs6: notif GoPay → server PayCore (pola QRIS Own)
// Jalankan di HP dengan GoPay login: buka file di AutoJs6 → ▶️ Run
// Syarat: Notification access untuk AutoJs6; Autostart ON; battery No restrictions.
var SERVER_URL = "https://domainmu.tld/studio/api.php?a=ingest"; // GANTI: endpoint ingest-mu
var INGEST_KEY = "GANTI_KEY";                                    // GANTI: key listener (server config)

// Filter paket e-wallet/banking. Tambah sesuai kebutuhan.
var PACKAGES = ["id.gopay", "id.gopay.consumer", "com.gojek.app", "com.gopay",
                "com.gojek.consumer", "id.gobiz", "com.gobiz"];

function postRaw(text, catatan) {
    try {
        var http = new java.net.URL(SERVER_URL).openConnection();
        http.setRequestMethod("POST");
        http.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
        http.setDoOutput(true);
        http.setConnectTimeout(10000);
        http.setReadTimeout(10000);
        var body = "key=" + java.net.URLEncoder.encode(INGEST_KEY, "UTF-8")
                 + "&text=" + java.net.URLEncoder.encode(text, "UTF-8")
                 + "&catatan=" + java.net.URLEncoder.encode(catatan, "UTF-8");
        var os = http.getOutputStream();
        os.write(new java.lang.String(body).getBytes("UTF-8"));
        os.flush();
        os.close();
        var code = http.getResponseCode();
        var resp = "";
        try {
            var is = http.getInputStream();
            var reader = new java.io.BufferedReader(new java.io.InputStreamReader(is));
            var line;
            while ((line = reader.readLine()) !== null) resp += line;
        } catch (e) {}
        log("[srv] " + code + " " + resp);
        return code;
    } catch (e) {
        log("[srv ERR] " + e);
        return 0;
    }
}

// WAJIB kirim dari thread terpisah — Android melarang network di callback notif
function postAsync(text, catatan) {
    threads.start(function () { postRaw(text, catatan); });
}

function isWalletNotif(pkg) {
    for (var i = 0; i < PACKAGES.length; i++) {
        if (pkg.indexOf(PACKAGES[i]) >= 0) return true;
    }
    return pkg.indexOf("gojek") >= 0 || pkg.indexOf("gopay") >= 0 ||
           pkg.indexOf("gobiz") >= 0;
}

// Beacon start (kompatibel AutoJs6: TIDAK pakai .get())
var beaconCode = 0;
threads.start(function () { beaconCode = postRaw("listener-START-" + new Date().getTime(), "beacon"); });
sleep(3000);
if (beaconCode === 200) { toast("listener: server OK ✓"); }
else { toast("listener: server GAGAL (" + beaconCode + ")"); }

try {
    events.observeNotification();
    log("=== listener AKTIF — menunggu notif… ===");
    toast("listener AKTIF ✓");
} catch (e) {
    log("❌ IZIN NOTIFIKASI BELUM AKTIF: " + e);
    toast("❌ Aktifkan Notification access untuk AutoJs6!");
}

events.on("notification", function (n) {
    try {
        var pkg = String(n.getPackageName() || "");
        var text = "";
        try { text = String(n.getText() || ""); } catch (e) {}
        var title = "";
        try { title = String(n.getTitle() || ""); } catch (e) {}
        var full = title + " | " + text;
        if (!full || full.length < 5) return;
        var lower = full.toLowerCase();
        if (!isWalletNotif(pkg)) return;
        if (lower.indexOf("rp") < 0 && lower.indexOf("dana") < 0 &&
            lower.indexOf("masuk") < 0 && lower.indexOf("idr") < 0) return;
        log("[HIT] " + full);
        postAsync(full, "listener");
    } catch (e) {
        log("[notif ERR] " + e);
    }
});

log("listener jalan — menunggu notif…");
setInterval(function () {}, 30000);
