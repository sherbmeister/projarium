(function () {
  const biometrics = window.Capacitor?.Plugins?.ProjariumBiometrics;
  const appInfo = window.Capacitor?.Plugins?.ProjariumAppInfo;
  const isMobileApp = Boolean(biometrics || window.Capacitor?.getPlatform?.() === "android");
  if (isMobileApp) document.body.classList.add("is-mobile-app");

  document.querySelectorAll("[data-mobile-app-field]").forEach((field) => {
    field.value = "1";
  });

  const rememberUnlock = () => {
    try { sessionStorage.setItem("projariumMobileUnlocked", String(Date.now())); } catch (_) {}
  };

  document.querySelectorAll("[data-auth-form]").forEach((form) => {
    form.addEventListener("submit", rememberUnlock);
  });

  document.querySelectorAll("[data-password-check]").forEach((input) => {
    const help = input.closest("label, div")?.querySelector("[data-password-help]");
    const update = () => {
      const value = input.value || "";
      const missing = [];
      if (value.length < 10) missing.push("10+ characters");
      if (!/[a-z]/.test(value)) missing.push("lowercase");
      if (!/[A-Z]/.test(value)) missing.push("uppercase");
      if (!/\d/.test(value)) missing.push("number");
      if (!/[^A-Za-z0-9]/.test(value)) missing.push("symbol");
      if (!help) return;
      help.classList.toggle("weak", missing.length > 0 && value.length > 0);
      help.classList.toggle("strong", missing.length === 0 && value.length > 0);
      help.textContent = missing.length
        ? `Password needs: ${missing.join(", ")}.`
        : "Strong password.";
    };
    input.addEventListener("input", update);
    update();
  });

  document.querySelectorAll("[data-captcha-refresh]").forEach((button) => {
    button.addEventListener("click", () => {
      const field = button.closest(".captcha-field");
      const image = field?.querySelector("[data-captcha-image]");
      const input = field?.querySelector('input[name="captcha_code"]');
      if (!image) return;
      image.src = `${image.dataset.captchaSource}&refresh=1&r=${Date.now()}`;
      if (input) input.value = "";
    });
  });

  const versionDialog = document.querySelector("[data-version-log]");
  if (versionDialog) {
    const close = () => typeof versionDialog.close === "function" ? versionDialog.close() : versionDialog.removeAttribute("open");
    document.querySelectorAll("[data-version-log-open]").forEach((button) => {
      button.addEventListener("click", () => typeof versionDialog.showModal === "function" ? versionDialog.showModal() : versionDialog.setAttribute("open", ""));
    });
    document.querySelector("[data-version-log-close]")?.addEventListener("click", close);
    versionDialog.addEventListener("click", (event) => {
      if (event.target === versionDialog) close();
    });
  }

  document.querySelectorAll("[data-confirm]").forEach((item) => {
    item.addEventListener("click", (event) => {
      if (!window.confirm(item.dataset.confirm || "Are you sure?")) event.preventDefault();
    });
  });

  const showUpdatePrompt = (installed, available) => {
    const key = `projariumUpdatePrompt:${available.versionCode}`;
    try {
      if (sessionStorage.getItem(key)) return false;
      sessionStorage.setItem(key, "1");
    } catch (_) {}
    const shell = document.createElement("div");
    shell.className = "app-update-popup";
    shell.innerHTML = `<div class="app-update-card" role="alertdialog" aria-live="polite"><img src="assets/icon.png" alt=""><p class="muted">Update available</p><h2>Projarium update available</h2><p class="muted">Installed ${installed.versionName || installed.versionCode}. Available ${available.versionName}.</p><div class="btn-row"><button type="button" data-update-now>Open Quantum Appstore</button><button class="secondary" type="button" data-update-later>Later</button></div></div>`;
    shell.querySelector("[data-update-later]")?.addEventListener("click", () => shell.remove());
    shell.querySelector("[data-update-now]")?.addEventListener("click", async () => {
      try {
        if (appInfo?.openAppstore) await appInfo.openAppstore();
        else window.location.href = "intent://appstore.quantumnet.space/#Intent;scheme=https;package=com.myname.mystore;S.browser_fallback_url=https%3A%2F%2Fappstore.quantumnet.space%2F;end";
      } catch (_) {
        window.location.href = "https://appstore.quantumnet.space/";
      }
      shell.remove();
    });
    document.body.appendChild(shell);
    return true;
  };

  const checkForAppUpdate = async () => {
    if (!isMobileApp) return;
    try {
      const response = await fetch("https://appstore.quantumnet.space/api/apps.json?t=" + Date.now(), { cache: "no-store" });
      if (!response.ok) return;
      const catalogue = await response.json();
      const installed = appInfo?.getInfo ? await appInfo.getInfo() : { packageName: "com.myname.projarium", versionName: "older app", versionCode: 0 };
      const app = (catalogue.apps || []).find((item) => item.package_name === installed.packageName);
      if (!app || Number(app.version_code) <= Number(installed.versionCode || 0)) return;
      const shown = showUpdatePrompt(installed, { versionName: app.version, versionCode: app.version_code });
      if (shown && appInfo?.notifyUpdateAvailable) {
        await appInfo.notifyUpdateAvailable({
          id: 97000 + (Number(app.version_code) % 1000),
          title: "Projarium update available",
          body: `Version ${app.version} is ready in Quantum Appstore.`
        });
      }
    } catch (_) {}
  };

  checkForAppUpdate();
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) checkForAppUpdate();
  });

  const runMobileLock = async () => {
    if (!isMobileApp || !biometrics || !document.body.matches("[data-mobile-session-lock]")) return;
    const unlockedAt = Number(sessionStorage.getItem("projariumMobileUnlocked") || "0");
    if (unlockedAt && Date.now() - unlockedAt < 3 * 60 * 1000) {
      rememberUnlock();
      return;
    }
    const overlay = document.createElement("div");
    overlay.className = "mobile-lock-overlay";
    overlay.innerHTML = `<div class="mobile-lock-card"><img src="assets/icon.png" alt=""><h2>Unlock Projarium</h2><p class="muted">Use fingerprint or device unlock to open the app.</p><button type="button" class="biometric-button">Unlock</button><a href="?a=logout">Sign out</a><p class="muted" data-lock-status></p></div>`;
    const button = overlay.querySelector("button");
    const status = overlay.querySelector("[data-lock-status]");
    document.body.appendChild(overlay);
    const unlock = async () => {
      button.disabled = true;
      status.textContent = "Waiting for fingerprint or device unlock.";
      try {
        const result = await biometrics.authenticate({ title: "Unlock Projarium", subtitle: "Use fingerprint or device unlock to open the app." });
        if (!result?.authenticated) throw new Error("Authentication was not completed.");
        rememberUnlock();
        overlay.remove();
      } catch (error) {
        status.textContent = error.message || "Unlock failed.";
        button.disabled = false;
      }
    };
    button.addEventListener("click", unlock);
    unlock();
  };
  runMobileLock();

  document.querySelectorAll("[data-mobile-biometric], [data-mobile-trusted-unlock]").forEach(async (panel) => {
    const button = panel.querySelector("[data-biometric-login], [data-mobile-unlock]");
    const status = panel.querySelector("[data-biometric-status], [data-mobile-unlock-status]");
    if (!button || !biometrics || !isMobileApp) return;
    try {
      const availability = await biometrics.isAvailable();
      if (!availability?.available) return;
      panel.hidden = false;
    } catch (_) { return; }
    button.addEventListener("click", async () => {
      button.disabled = true;
      if (status) status.textContent = "Waiting for fingerprint or device unlock.";
      try {
        const result = await biometrics.authenticate({ title: "Unlock Projarium", subtitle: "Use fingerprint or device unlock." });
        if (!result?.authenticated) throw new Error("Authentication was not completed.");
        const action = button.matches("[data-mobile-unlock]") ? "?a=mobile-unlock" : "?a=mobile-biometric-login";
        const response = await fetch(action, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
          body: new URLSearchParams({ csrf: button.dataset.csrf || "" }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.message || "Mobile unlock failed.");
        rememberUnlock();
        window.location.href = payload.redirect || "?";
      } catch (error) {
        if (status) status.textContent = error.message || "Mobile unlock failed.";
        button.disabled = false;
      }
    });
  });
})();
