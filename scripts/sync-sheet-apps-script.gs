const GOOGLE_DOC_MIME = "application/vnd.google-apps.document";

function syncConfig_() {
  const properties = PropertiesService.getScriptProperties();
  const docId = properties.getProperty("PROJECT_SHEET_DOC_ID");
  const token = properties.getProperty("SECRET_TOKEN");
  if (!docId || !token) {
    throw new Error("Set PROJECT_SHEET_DOC_ID and SECRET_TOKEN in Script Properties.");
  }
  return { docId, token };
}

function syncJson_(value) {
  return ContentService.createTextOutput(JSON.stringify(value))
    .setMimeType(ContentService.MimeType.JSON);
}

function syncHash_(text) {
  return Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256, text, Utilities.Charset.UTF_8
  ).map(function (byte) {
    return ("0" + (byte & 255).toString(16)).slice(-2);
  }).join("");
}

function syncFetch_(url, options) {
  const response = UrlFetchApp.fetch(url, Object.assign({}, options, {
    headers: { Authorization: "Bearer " + ScriptApp.getOAuthToken() },
    muteHttpExceptions: true,
  }));
  const status = response.getResponseCode();
  if (status < 200 || status >= 300) {
    throw new Error("Drive API returned HTTP " + status + ". Check authorization, Drive API enablement and document access.");
  }
  return response.getContentText("UTF-8");
}

function syncExport_(docId) {
  const markdown = syncFetch_(
    "https://www.googleapis.com/drive/v3/files/" + encodeURIComponent(docId) +
    "/export?mimeType=text%2Fmarkdown",
    { method: "get" }
  );
  if (!markdown.trim() || /^\s*<(?:!doctype\s+html|html)\b/i.test(markdown)) {
    throw new Error("Drive returned empty Markdown or HTML; refusing synchronization.");
  }
  return { markdown: markdown, hash: syncHash_(markdown) };
}

function authorizeSync() {
  const config = syncConfig_();
  syncExport_(config.docId);
  const formats = JSON.parse(syncFetch_(
    "https://www.googleapis.com/drive/v3/about?fields=importFormats",
    { method: "get" }
  )).importFormats;
  if (!formats || !(formats["text/markdown"] || []).includes(GOOGLE_DOC_MIME)) {
    throw new Error("This Drive account does not advertise Markdown-to-Google-Docs import.");
  }
  console.log("Authorization, Markdown export and native Markdown import support verified. No document was modified.");
}

function doGet() {
  return syncJson_({ ok: false, error: "Use the local sync client, which sends authenticated JSON POST requests." });
}

function doPost(e) {
  let lock;
  try {
    const input = JSON.parse(e && e.postData ? e.postData.contents : "{}");
    const config = syncConfig_();
    if (!input || input.token !== config.token) {
      return syncJson_({ ok: false, error: "Unauthorized." });
    }
    if (!["pull", "push"].includes(input.action)) {
      return syncJson_({ ok: false, error: "Invalid sync action." });
    }
    lock = LockService.getScriptLock();
    lock.waitLock(30000);
    const current = syncExport_(config.docId);
    if (input.action === "pull") {
      return syncJson_({ ok: true, markdown: current.markdown, hash: current.hash });
    }
    if (typeof input.markdown !== "string" || !input.markdown.trim() ||
        /^\s*<(?:!doctype\s+html|html)\b/i.test(input.markdown) ||
        !/^[a-f0-9]{64}$/.test(input.baseHash)) {
      return syncJson_({ ok: false, error: "Invalid Markdown or missing baseline. Pull before pushing." });
    }
    if (input.baseHash !== current.hash) {
      return syncJson_({ ok: false, error: "The Google Doc changed since your last sync. Reconcile remote and local edits before pushing." });
    }
    const formats = JSON.parse(syncFetch_(
      "https://www.googleapis.com/drive/v3/about?fields=importFormats",
      { method: "get" }
    )).importFormats;
    if (!formats || !(formats["text/markdown"] || []).includes(GOOGLE_DOC_MIME)) {
      throw new Error("Markdown-to-Google-Docs import is not supported for this account.");
    }
    const boundary = "sync_sheet_" + Utilities.getUuid();
    const payload = "--" + boundary + "\r\n" +
      "Content-Type: application/json; charset=UTF-8\r\n\r\n" +
      JSON.stringify({ mimeType: GOOGLE_DOC_MIME }) + "\r\n" +
      "--" + boundary + "\r\nContent-Type: text/markdown; charset=UTF-8\r\n\r\n" +
      input.markdown + "\r\n--" + boundary + "--";
    const updated = JSON.parse(syncFetch_(
      "https://www.googleapis.com/upload/drive/v3/files/" + encodeURIComponent(config.docId) +
      "?uploadType=multipart&fields=id,mimeType",
      {
        method: "patch",
        contentType: "multipart/related; boundary=" + boundary,
        payload: Utilities.newBlob(payload).getBytes(),
      }
    ));
    if (updated.id !== config.docId || updated.mimeType !== GOOGLE_DOC_MIME) {
      throw new Error("Unexpected Drive update response. Inspect the document before retrying.");
    }
    const exported = syncExport_(config.docId);
    return syncJson_({ ok: true, hash: exported.hash });
  } catch (error) {
    return syncJson_({ ok: false, error: String(error.message || error) });
  } finally {
    if (lock && lock.hasLock()) {
      lock.releaseLock();
    }
  }
}
