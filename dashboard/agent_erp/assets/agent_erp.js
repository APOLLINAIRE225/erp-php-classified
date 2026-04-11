(function () {
  const cfg = window.AGENT_ERP_CONFIG || {};
  const endpoint = cfg.controllerUrl || "agent_erp_controller.php";
  const csrf = cfg.csrfToken || "";
  const mustChangePassword = !!cfg.mustChangePassword;
  const isAdmin = !!cfg.isAdmin;

  const $ = (id) => document.getElementById(id);
  const paneIds = ["chat", "kb", "history", "stats", "admin", "admin-users", "admin-audit", "admin-diag"];
  const intentIcons = { how_to: "fa-question-circle", action: "fa-bolt", info: "fa-info-circle", diagnostic: "fa-stethoscope", doc: "fa-file-alt" };
  const catMap = { stock: "cs", finance: "cf", rh: "cr", admin: "ca", clients: "cc", general: "cg" };
  let currentEditId = 0;
  let kbPage = 1;
  let autoCompleteTimer = null;
  let confirmResolver = null;

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function truncate(value, size) {
    const str = String(value ?? "");
    return str.length > size ? str.slice(0, size) + "…" : str;
  }

  function safeJson(value) {
    return JSON.stringify(value).replace(/</g, "\\u003c");
  }

  function fmt(markdown) {
    if (!markdown) return "";
    let s = escapeHtml(markdown);
    s = s.replace(/(\|[^\n]+\|\n?\|[-| :]+\|\n?(?:\|[^\n]+\|\n?)+)/g, (block) => {
      const rows = block.trim().split("\n");
      let html = "<table>";
      rows.forEach((row, index) => {
        if (/^[\|\s\-:]+$/.test(row)) return;
        const cells = row.split("|").filter((_, idx, arr) => idx > 0 && idx < arr.length - 1);
        html += `<tr>${cells.map((cell) => `<${index === 0 ? "th" : "td"}>${cell.trim()}</${index === 0 ? "th" : "td"}>`).join("")}</tr>`;
      });
      return html + "</table>";
    });
    s = s.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
    s = s.replace(/\*(.+?)\*/g, "<em>$1</em>");
    s = s.replace(/`(.+?)`/g, "<code>$1</code>");
    s = s.replace(/\n/g, "<br>");
    return s;
  }

  function now() {
    return new Date().toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  }

  function apiUrl(action, params = {}) {
    const url = new URL(endpoint, window.location.href);
    url.searchParams.set("ajax", action);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") {
        url.searchParams.set(key, value);
      }
    });
    return url.toString();
  }

  function toast(msg, type = "info", sub = "") {
    const colors = { success: "var(--neon)", error: "var(--red)", info: "var(--cyan)", warn: "var(--gold)" };
    const icons = { success: "fa-check-circle", error: "fa-times-circle", info: "fa-info-circle", warn: "fa-exclamation-triangle" };
    const wrap = $("ts");
    if (!wrap) return;
    const el = document.createElement("div");
    el.className = "toast";
    el.innerHTML = `<div class="tico" style="background:${colors[type]}22;color:${colors[type]}"><i class="fas ${icons[type]}"></i></div><div class="ttxt"><strong style="color:${colors[type]}">${escapeHtml(msg)}</strong>${sub ? `<span>${escapeHtml(sub)}</span>` : ""}</div>`;
    wrap.appendChild(el);
    setTimeout(() => {
      el.classList.add("out");
      setTimeout(() => el.remove(), 350);
    }, 4200);
  }

  function openM(id) {
    $(id)?.classList.add("show");
  }

  function closeM(id) {
    $(id)?.classList.remove("show");
  }

  window.openM = openM;
  window.closeM = closeM;

  function confirmAction(message) {
    return new Promise((resolve) => {
      confirmResolver = resolve;
      $("confMsg").textContent = message;
      $("confOverlay").classList.add("show");
    });
  }

  function closeConf(result = false) {
    $("confOverlay")?.classList.remove("show");
    if (confirmResolver) {
      const resolver = confirmResolver;
      confirmResolver = null;
      resolver(result);
    }
  }

  window.closeConf = () => closeConf(false);

  function setTab(name) {
    paneIds.forEach((pane) => {
      const panel = $("p-" + pane);
      const tab = $("tab-" + pane);
      if (panel) panel.style.display = pane === name ? "block" : "none";
      if (tab) tab.classList.toggle("active", pane === name);
    });
    if (name === "kb") loadKB(1);
    if (name === "history") loadHistory();
    if (name === "stats") loadStats();
    if (name === "admin-users") loadUsers();
    if (name === "admin-audit") loadAudit();
  }

  window.st = setTab;

  function addMsg(role, html, extraHtml = "") {
    const container = $("msgs");
    if (!container) return null;
    const item = document.createElement("div");
    item.className = `msg ${role}`;
    item.innerHTML = `<div class="bbl">${html}</div>${extraHtml}<span class="mtime">${now()}</span>`;
    container.appendChild(item);
    container.scrollTop = container.scrollHeight;
    return item;
  }

  function updateContextBadge(delta = 1) {
    const badge = document.querySelector(".ctxbadge");
    if (!badge) return;
    const match = badge.textContent.match(/(\d+)/);
    if (!match) return;
    badge.textContent = badge.textContent.replace(match[0], String(Math.max(0, parseInt(match[0], 10) + delta)));
  }

  function createRelated(items) {
    if (!items || !items.length) return "";
    return `<div class="rel"><div style="font-size:9px;font-weight:800;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:4px">Voir aussi</div>${items.map((item) => `<div class="reli" onclick='ask(${safeJson(item.question)})'>${escapeHtml(item.question)}</div>`).join("")}</div>`;
  }

  async function sendStream() {
    const input = $("ci");
    if (!input) return;
    const question = input.value.trim();
    if (!question) return;
    input.value = "";
    $("acd").style.display = "none";
    $("qgrid").style.display = "none";
    $("sb").disabled = true;
    addMsg("user", fmt(question));

    const wrap = $("msgs");
    const agent = document.createElement("div");
    agent.className = "msg agent";
    const bubble = document.createElement("div");
    bubble.className = "bbl";
    bubble.innerHTML = '<span class="cursor"></span>';
    agent.appendChild(bubble);
    wrap.appendChild(agent);
    wrap.scrollTop = wrap.scrollHeight;

    let accumulated = "";
    try {
      const response = await fetch(apiUrl("stream", { q: question }), {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `q=${encodeURIComponent(question)}`,
      });
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = "";
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split("\n");
        buffer = lines.pop() || "";
        for (const line of lines) {
          if (!line.startsWith("data:")) continue;
          const raw = line.slice(5).trim();
          if (!raw) continue;
          let event;
          try {
            event = JSON.parse(raw);
          } catch (error) {
            continue;
          }
          if (event.chunk !== undefined) {
            accumulated += event.chunk;
            bubble.innerHTML = fmt(accumulated) + '<span class="cursor"></span>';
            wrap.scrollTop = wrap.scrollHeight;
          }
          if (event.done) {
            bubble.innerHTML = event.error ? `<span style="color:var(--red)">${escapeHtml(event.error)}</span>` : fmt(accumulated || event.message || "");
            if (!event.error && event.found !== false) {
              const badge = document.createElement("div");
              badge.className = "ibadge";
              badge.innerHTML = `<i class="fas ${intentIcons[event.intent] || "fa-circle"}"></i> ${escapeHtml(event.intent || "how_to")} · ${event.lang === "en" ? "🇬🇧" : "🇫🇷"} ${escapeHtml(event.lang || "fr")}`;
              bubble.insertAdjacentElement("beforebegin", badge);
              const extra = document.createElement("div");
              extra.innerHTML = createRelated(event.related);
              while (extra.firstChild) bubble.appendChild(extra.firstChild);
              if (event.action_url && event.has_access) {
                const card = document.createElement("a");
                card.href = event.action_url;
                card.target = "_blank";
                card.className = "acard";
                card.rel = "noopener noreferrer";
                card.innerHTML = `<div class="aico"><i class="fas fa-arrow-up-right-from-square"></i></div><div class="atxt"><strong>${escapeHtml(event.action_label || "Accéder")}</strong><span>${escapeHtml(event.action_url)}</span></div><i class="fas fa-chevron-right" style="color:var(--muted);margin-left:auto;font-size:10px"></i>`;
                bubble.appendChild(card);
              }
              const feedback = document.createElement("div");
              feedback.className = "fbr";
              feedback.innerHTML = `<span style="font-size:10px;color:var(--muted)">Utile ?</span><button class="fbb pos"><i class="fas fa-thumbs-up"></i> Oui</button><button class="fbb neg"><i class="fas fa-thumbs-down"></i> Non</button>`;
              const [positiveBtn, negativeBtn] = feedback.querySelectorAll("button");
              positiveBtn.addEventListener("click", () => sendFeedback(event.log_id, 1, feedback));
              negativeBtn.addEventListener("click", () => sendFeedback(event.log_id, -1, feedback));
              agent.appendChild(feedback);
              const asks = $("kasks");
              if (asks) asks.textContent = String(parseInt(asks.textContent || "0", 10) + 1);
              updateContextBadge(1);
            } else if (event.can_learn) {
              const id = event.log_id || Math.random().toString(36).slice(2);
              const panel = document.createElement("div");
              panel.className = "lp";
              panel.id = "learn-" + id;
              panel.innerHTML = `<h4><i class="fas fa-graduation-cap"></i> Apprenez-moi cette procédure</h4>
                <div class="fg"><label>Réponse *</label><textarea id="learn-answer-${id}" style="min-height:80px" placeholder="Décrivez les étapes…"></textarea></div>
                <div class="fgr">
                  <div class="fg"><label>Lien ERP</label><input type="text" id="learn-url-${id}" placeholder="https://…"></div>
                  <div class="fg"><label>Catégorie</label><select id="learn-cat-${id}"><option value="general">general</option><option value="stock">stock</option><option value="finance">finance</option><option value="rh">rh</option><option value="admin">admin</option><option value="clients">clients</option></select></div>
                </div>
                <button class="btn btn-g btn-sm" id="learn-btn-${id}"><i class="fas fa-save"></i> Enseigner l'agent</button>`;
              agent.appendChild(panel);
              $("learn-btn-" + id).addEventListener("click", () => learnInline(question, id));
            }
          }
        }
      }
    } catch (error) {
      bubble.innerHTML = `<span style="color:var(--red)">Erreur réseau: ${escapeHtml(error.message)}</span>`;
    }
    const time = document.createElement("span");
    time.className = "mtime";
    time.textContent = now();
    agent.appendChild(time);
    wrap.scrollTop = wrap.scrollHeight;
    $("sb").disabled = false;
  }

  window.sendStream = sendStream;
  window.ask = function ask(question) {
    $("ci").value = question;
    sendStream();
  };

  async function sendFeedback(logId, value, wrap) {
    wrap.innerHTML = value === 1 ? '<span style="color:var(--neon);font-size:10px"><i class="fas fa-check"></i> Merci !</span>' : '<span style="color:var(--muted);font-size:10px">Noté !</span>';
    await fetch(apiUrl("feedback", { log_id: logId, val: value, csrf_token: csrf }));
  }

  async function clearContext() {
    const response = await fetch(apiUrl("context_clear", { csrf_token: csrf }));
    const data = await response.json();
    if (data.ok) {
      document.querySelector(".ctxbadge")?.remove();
      toast("Contexte effacé", "info");
    } else {
      toast(data.msg || "Erreur", "error");
    }
  }

  window.clearCtx = clearContext;

  async function learnInline(question, id) {
    const answer = $("learn-answer-" + id)?.value.trim();
    const url = $("learn-url-" + id)?.value.trim();
    const category = $("learn-cat-" + id)?.value || "general";
    if (!answer) {
      toast("Écrivez la procédure d'abord", "error");
      return;
    }
    const response = await fetch(apiUrl("learn"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ question, answer, url, category, csrf_token: csrf }),
    });
    const data = await response.json();
    const wrap = $("learn-" + id);
    if (data.ok) {
      if (wrap) wrap.innerHTML = `<div style="color:var(--neon);font-family:var(--fh);font-size:12px;font-weight:900;padding:6px 0"><i class="fas fa-check-circle"></i> ${escapeHtml(data.msg)}</div>`;
      const kkb = $("kkb");
      if (kkb) kkb.textContent = String(parseInt(kkb.textContent || "0", 10) + 1);
      toast("Base enrichie", "success", "Auto-learning");
      setTimeout(() => window.ask(question), 500);
    } else {
      toast(data.msg || "Erreur", "error");
    }
  }

  window.tFrom = function tFrom(question) {
    $("lq").value = question;
    openM("ml");
  };

  window.subLearn = async function subLearn() {
    const payload = {
      question: $("lq").value.trim(),
      answer: $("la").value.trim(),
      url: $("lu").value.trim(),
      label: $("ll").value.trim(),
      category: $("lcat").value,
      intent: $("lint").value,
      permissions: $("lperm") ? $("lperm").value.trim() : "",
      company_scope: $("lcompany") ? $("lcompany").value : "general",
      csrf_token: csrf,
    };
    if (!payload.question || !payload.answer) {
      toast("Question et réponse requises", "error");
      return;
    }
    const response = await fetch(apiUrl("learn"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (data.ok) {
      toast(data.msg, "success", "Auto-learning");
      closeM("ml");
      ["lq", "la", "lu", "ll", "lperm"].forEach((id) => $(id) && ($(id).value = ""));
      const kkb = $("kkb");
      if (kkb) kkb.textContent = String(parseInt(kkb.textContent || "0", 10) + 1);
      setTimeout(() => window.ask(payload.question), 450);
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  window.onInp = function onInp() {
    const q = $("ci").value;
    clearTimeout(autoCompleteTimer);
    if (q.length < 2) {
      $("acd").style.display = "none";
      return;
    }
    autoCompleteTimer = setTimeout(async () => {
      try {
        const response = await fetch(apiUrl("suggest", { q }));
        const data = await response.json();
        const acd = $("acd");
        if (!Array.isArray(data) || !data.length) {
          acd.style.display = "none";
          return;
        }
        acd.innerHTML = data.map((item) => `<div class="aci" onclick='ask(${safeJson(item.question)})'><i class="fas fa-search"></i><span style="flex:1">${escapeHtml(truncate(item.question, 52))}</span><span class="acat">${escapeHtml(item.category)}</span><span class="aintt"><i class="fas ${intentIcons[item.intent_type] || "fa-circle"}"></i></span></div>`).join("");
        acd.style.display = "block";
      } catch (error) {
      }
    }, 240);
  };

  window.onKey = function onKey(event) {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      sendStream();
    }
    if (event.key === "Escape") {
      $("acd").style.display = "none";
    }
  };

  async function loadKB(page = 1) {
    kbPage = page;
    const params = {
      cat: $("kbcat")?.value || "",
      intent: $("kbintent")?.value || "",
      company_scope: $("kbcompany")?.value || "",
      search: $("kbsearch")?.value || "",
      page,
    };
    const response = await fetch(apiUrl("kb_list", params));
    const data = await response.json();
    const rows = data.rows || [];
    const tbody = $("kbtb");
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)">Aucune entrée</td></tr>';
    } else {
      tbody.innerHTML = rows.map((row) => `<tr>
        <td style="color:var(--muted);font-size:10px">#${row.id}</td>
        <td style="max-width:220px"><strong>${escapeHtml(truncate(row.question, 48))}</strong></td>
        <td><span class="cat ${catMap[row.category] || "cg"}">${escapeHtml(row.category)}</span></td>
        <td style="font-size:10px;color:var(--muted)">${escapeHtml(row.company_scope || "general")}</td>
        <td><span class="cat cc" style="font-size:9px"><i class="fas ${intentIcons[row.intent_type] || "fa-circle"}"></i> ${escapeHtml(row.intent_type || "?")}</span></td>
        <td style="font-size:10px;color:var(--cyan);text-align:center">v${escapeHtml(row.version || 1)}</td>
        <td><div style="display:flex;align-items:center;gap:6px"><div style="width:40px;height:3px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden"><div style="height:100%;background:linear-gradient(90deg,var(--purple),var(--neon));width:${Math.min(100, (row.hits || 0) * 10)}%"></div></div><span style="font-size:10px;color:var(--muted)">${escapeHtml(row.hits || 0)}</span></div></td>
        <td style="font-size:9px;color:var(--muted)">${escapeHtml((row.updated_at || row.created_at || "").slice(0, 16))}</td>
        <td><div style="display:flex;gap:3px"><button onclick='ask(${safeJson(row.question)});st("chat")' class="btn btn-c btn-xs"><i class="fas fa-play"></i></button>${isAdmin ? `<button onclick="openEditKB(${row.id})" class="btn btn-b btn-xs"><i class="fas fa-edit"></i></button><button onclick="delKB(${row.id})" class="btn btn-r btn-xs"><i class="fas fa-trash"></i></button>` : ""}</div></td>
      </tr>`).join("");
    }
    $("kbinfo").textContent = `${data.total || 0} entrée(s) · Page ${data.page || 1}/${data.pages || 1}`;
    renderPagination(data.pages || 1, page);
  }

  function renderPagination(totalPages, currentPage) {
    const wrap = $("kbpgn");
    if (!wrap) return;
    wrap.innerHTML = "";
    const prev = document.createElement("button");
    prev.className = "pgbtn";
    prev.innerHTML = "‹";
    prev.disabled = currentPage <= 1;
    prev.onclick = () => loadKB(currentPage - 1);
    wrap.appendChild(prev);
    for (let i = 1; i <= totalPages; i += 1) {
      const btn = document.createElement("button");
      btn.className = "pgbtn" + (i === currentPage ? " active" : "");
      btn.textContent = String(i);
      btn.onclick = () => loadKB(i);
      wrap.appendChild(btn);
    }
    const next = document.createElement("button");
    next.className = "pgbtn";
    next.innerHTML = "›";
    next.disabled = currentPage >= totalPages;
    next.onclick = () => loadKB(currentPage + 1);
    wrap.appendChild(next);
  }

  window.loadKB = loadKB;
  window.debKB = function debKB() {
    clearTimeout(autoCompleteTimer);
    autoCompleteTimer = setTimeout(() => loadKB(1), 260);
  };

  window.openEditKB = async function openEditKB(id) {
    currentEditId = id;
    const response = await fetch(apiUrl("kb_get", { id }));
    const data = await response.json();
    if (!data || !data.id) {
      toast("Entrée introuvable", "error");
      return;
    }
    $("eid").value = data.id;
    $("editIdBadge").textContent = `#${data.id} v${data.version || 1}`;
    $("eq").value = data.question || "";
    $("ea").value = data.answer || "";
    $("eu").value = data.action_url || "";
    $("el").value = data.action_label || "";
    $("eperm").value = data.access_permissions || "";
    $("ecat").value = data.category || "general";
    $("ecompany").value = data.company_scope || "general";
    $("eint").value = data.intent_type || "how_to";
    $("ehistory").innerHTML = "";
    openM("medit");
  };

  window.loadKbHistory = async function loadKbHistory() {
    const response = await fetch(apiUrl("kb_history", { id: currentEditId }));
    const data = await response.json();
    const box = $("ehistory");
    if (!Array.isArray(data) || !data.length) {
      box.innerHTML = '<div style="font-size:11px;color:var(--muted);padding:8px 0">Aucun historique.</div>';
      return;
    }
    box.innerHTML = `<div style="font-family:var(--fh);font-size:11px;font-weight:900;color:var(--cyan);margin:10px 0 6px"><i class="fas fa-history"></i> Historique des versions</div><div style="max-height:200px;overflow-y:auto">${data.map((item) => `<div style="background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:8px 10px;margin-bottom:6px;font-size:11px"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px"><span style="color:var(--cyan);font-family:var(--fh);font-weight:900">v${escapeHtml(item.version)}</span><span style="color:var(--muted);font-size:9px">${escapeHtml((item.changed_at || "").slice(0, 16))} · ${escapeHtml(item.username || "?")}</span><button class="btn btn-g btn-xs" onclick="restoreVersion(${item.id},${safeJson(item.change_note || "")})"><i class="fas fa-undo"></i> Restaurer</button></div><div style="color:var(--text2);font-size:10px">${escapeHtml(truncate(item.answer || "", 100))}</div>${item.change_note ? `<div style="color:var(--muted);font-size:9px;margin-top:3px"><em>${escapeHtml(item.change_note)}</em></div>` : ""}</div>`).join("")}</div>`;
  };

  window.restoreVersion = async function restoreVersion(historyId, note) {
    const ok = await confirmAction(`Restaurer cette version ?\n${note || ""}`);
    if (!ok) return;
    const response = await fetch(apiUrl("kb_restore", { hid: historyId, csrf_token: csrf }));
    const data = await response.json();
    if (data.ok) {
      toast(data.msg, "success");
      closeM("medit");
      loadKB(kbPage);
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  window.subEdit = async function subEdit() {
    const payload = {
      id: parseInt($("eid").value, 10),
      question: $("eq").value.trim(),
      answer: $("ea").value.trim(),
      url: $("eu").value.trim(),
      label: $("el").value.trim(),
      category: $("ecat").value,
      company_scope: $("ecompany").value,
      permissions: $("eperm").value.trim(),
      intent: $("eint").value,
      csrf_token: csrf,
    };
    if (!payload.question || !payload.answer) {
      toast("Question et réponse requises", "error");
      return;
    }
    const response = await fetch(apiUrl("kb_edit"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (data.ok) {
      toast(data.msg, "success");
      closeM("medit");
      loadKB(kbPage);
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  window.delKB = async function delKB(id) {
    const ok = await confirmAction(`Supprimer l'entrée KB #${id} ?`);
    if (!ok) return;
    const response = await fetch(apiUrl("kb_delete", { id, csrf_token: csrf }));
    const data = await response.json();
    if (data.ok) {
      toast("Entrée supprimée", "warn");
      loadKB(kbPage);
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  window.subImport = async function subImport() {
    const file = $("impfile").files[0];
    if (!file) {
      toast("Sélectionnez un fichier", "error");
      return;
    }
    const fd = new FormData();
    fd.append("file", file);
    fd.append("csrf_token", csrf);
    $("impres").textContent = "Import…";
    const response = await fetch(apiUrl("kb_import"), { method: "POST", body: fd });
    const data = await response.json();
    $("impres").textContent = data.msg || "";
    if (data.ok) {
      toast("Import OK", "success");
      closeM("mimport");
      loadKB(1);
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  async function loadHistory() {
    const wrap = $("histcont");
    wrap.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    const response = await fetch(apiUrl("my_history"));
    const data = await response.json();
    if (!Array.isArray(data) || !data.length) {
      wrap.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted);font-size:12px">Aucune question posée.</div>';
      return;
    }
    wrap.innerHTML = data.map((item) => `<div class="hrow"><div style="flex:1"><div style="font-size:12px;color:var(--text)">${escapeHtml(item.question || "—")}</div>${item.kb_q ? `<div style="font-size:10px;color:var(--neon);margin-top:2px"><i class="fas fa-check-circle"></i> ${escapeHtml(truncate(item.kb_q, 60))}</div>` : '<div style="font-size:10px;color:var(--red);margin-top:2px"><i class="fas fa-times-circle"></i> Sans réponse</div>'}</div><div style="display:flex;align-items:center;gap:6px;flex-shrink:0">${item.category ? `<span class="cat ${catMap[item.category] || "cg"}">${escapeHtml(item.category)}</span>` : ""}${item.intent_type ? `<span class="cat cc"><i class="fas ${intentIcons[item.intent_type] || "fa-circle"}"></i></span>` : ""}${item.lang_detected ? `<span style="font-size:10px;color:var(--gold)">${escapeHtml(item.lang_detected.toUpperCase())}</span>` : ""}${item.response_ms ? `<span style="font-size:9px;color:var(--muted)">${escapeHtml(item.response_ms)}ms</span>` : ""}${item.company_scope ? `<span style="font-size:9px;color:var(--muted)">${escapeHtml(item.company_scope)}</span>` : ""}<span style="font-size:9px;color:var(--muted)">${escapeHtml((item.created_at || "").slice(0, 16))}</span></div></div>`).join("");
  }

  window.loadHistory = loadHistory;

  async function loadStats() {
    const response = await fetch(apiUrl("stats"));
    const data = await response.json();
    const rateColor = data.answer_rate >= 80 ? "var(--neon)" : data.answer_rate >= 50 ? "var(--orange)" : "var(--red)";
    $("sc").innerHTML = `<div class="kpis">
      <div class="ks"><div class="ksico" style="background:rgba(168,85,247,.16);color:var(--purple)"><i class="fas fa-brain"></i></div><div><div class="ksv" style="color:var(--purple)">${escapeHtml(data.total_kb || 0)}</div><div class="ksl">Entrées KB</div></div></div>
      <div class="ks"><div class="ksico" style="background:rgba(50,190,143,.16);color:var(--neon)"><i class="fas fa-comments"></i></div><div><div class="ksv" style="color:var(--neon)">${escapeHtml(data.total_asks || 0)}</div><div class="ksl">Questions</div></div></div>
      <div class="ks"><div class="ksico" style="background:rgba(6,182,212,.16);color:var(--cyan)"><i class="fas fa-check-circle"></i></div><div><div class="ksv" style="color:var(--cyan)">${escapeHtml(data.answered || 0)}</div><div class="ksl">Répondues</div></div></div>
      <div class="ks"><div class="ksico" style="background:rgba(255,53,83,.16);color:var(--red)"><i class="fas fa-question-circle"></i></div><div><div class="ksv" style="color:var(--red)">${escapeHtml(data.unanswered || 0)}</div><div class="ksl">Sans réponse</div></div></div>
      <div class="ks"><div class="ksico" style="background:rgba(255,208,96,.16);color:var(--gold)"><i class="fas fa-thumbs-up"></i></div><div><div class="ksv" style="color:var(--gold)">${escapeHtml(data.positive_fb || 0)}</div><div class="ksl">Feedback +</div></div></div>
      <div class="ks"><div class="ksico" style="background:rgba(50,190,143,.16);color:var(--neon)"><i class="fas fa-clock"></i></div><div><div class="ksv" style="color:var(--neon)">${escapeHtml(data.avg_ms || 0)}</div><div class="ksl">ms moy.</div></div></div>
    </div>
    <div class="grid-3">
      <div class="panel"><div class="ph"><div class="pht"><div class="dot n"></div> Taux de réponse</div><span class="pbadge n">${escapeHtml(data.answer_rate || 0)}%</span></div><div class="pb" style="text-align:center;padding:18px 0"><div style="font-family:var(--fh);font-size:3rem;font-weight:900;color:${rateColor}">${escapeHtml(data.answer_rate || 0)}%</div><div style="font-size:11px;color:var(--muted)">${escapeHtml(data.answered || 0)} / ${escapeHtml(data.total_asks || 0)}</div></div></div>
      <div class="panel"><div class="ph"><div class="pht"><div class="dot p"></div> Sans réponse groupées</div></div><div class="pb">${(data.unanswered_groups || []).map((row) => `<div class="sbr"><div class="sbn">${escapeHtml(truncate(row.question_sample, 40))}</div><div class="sbw"><div class="sbf" style="width:${Math.min(100, (row.cnt || 0) * 10)}%"></div></div><div class="sbvl">${escapeHtml(row.cnt || 0)}</div></div>`).join("") || '<div style="color:var(--muted)">Aucune</div>'}</div></div>
      <div class="panel"><div class="ph"><div class="pht"><div class="dot g"></div> Temps par intention</div></div><div class="pb">${(data.avg_by_intent || []).map((row) => `<div class="sbr"><div class="sbn">${escapeHtml(row.intent_type)}</div><div class="sbw"><div class="sbf" style="width:${Math.min(100, Math.round((row.avg_ms || 0) / 10))}%"></div></div><div class="sbvl">${escapeHtml(row.avg_ms || 0)}</div></div>`).join("") || '<div style="color:var(--muted)">Pas de données</div>'}</div></div>
    </div>
    <div class="grid-2-tight">
      <div class="panel"><div class="ph"><div class="pht"><div class="dot n"></div> Satisfaction par rôle</div></div><div class="pb">${(data.satisfaction_by_role || []).map((row) => `<div class="hrow"><div style="flex:1">${escapeHtml(row.user_role || "n/a")}</div><div style="font-size:10px;color:var(--muted)">${escapeHtml(row.positive || 0)}/${escapeHtml(row.total || 0)}</div><span class="cat cc">${escapeHtml(row.rate || 0)}%</span></div>`).join("") || '<div style="color:var(--muted)">Pas de feedback</div>'}</div></div>
      <div class="panel"><div class="ph"><div class="pht"><div class="dot p"></div> Top procédures par société</div></div><div class="pb">${(data.top_by_company || []).map((row) => `<div class="hrow"><div style="flex:1"><div style="font-size:11px;color:var(--text)">${escapeHtml(truncate(row.question, 52))}</div><div style="font-size:9px;color:var(--muted)">${escapeHtml(row.company_scope || "general")}</div></div><span class="cat cg">${escapeHtml(row.usage_count || 0)}</span></div>`).join("") || '<div style="color:var(--muted)">Pas de données</div>'}</div></div>
    </div>`;
  }

  window.loadStats = loadStats;

  async function loadUsers() {
    const response = await fetch(apiUrl("user_list"));
    const data = await response.json();
    const tbody = $("usertb");
    if (!Array.isArray(data) || !data.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">Aucun utilisateur</td></tr>';
      return;
    }
    tbody.innerHTML = data.map((row) => `<tr>
      <td style="color:var(--muted);font-size:10px">#${row.id}</td>
      <td><div style="display:flex;align-items:center;gap:8px"><div style="width:26px;height:26px;border-radius:7px;background:${escapeHtml(row.avatar_color || "#a855f7")};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:#fff">${escapeHtml((row.full_name || row.username || "?").charAt(0).toUpperCase())}</div><div><strong style="font-size:12px">${escapeHtml(row.full_name || row.username)}</strong><div style="font-size:9px;color:var(--muted)">@${escapeHtml(row.username)}</div></div></div></td>
      <td><span class="cat ${row.role === "admin" ? "ca" : row.role === "PDG" ? "cf" : row.role === "developer" ? "cr" : "cg"}">${escapeHtml(row.role)}</span></td>
      <td>${row.is_active ? '<span style="color:var(--neon);font-size:10px">Actif</span>' : '<span style="color:var(--red);font-size:10px">Inactif</span>'}</td>
      <td>${row.must_change_password ? '<span style="color:var(--gold);font-size:10px">Rotation requise</span>' : '<span style="color:var(--muted);font-size:10px">OK</span>'}</td>
      <td style="font-size:10px;color:var(--muted)">${escapeHtml(row.last_login ? row.last_login.slice(0, 16) : "Jamais")}</td>
      <td><div style="display:flex;gap:3px"><button class="btn btn-b btn-xs" onclick='openUserModal(${row.id},${safeJson(row)})'><i class="fas fa-edit"></i></button>${row.login_attempts >= 5 ? `<button class="btn btn-g btn-xs" onclick="unlockUser(${row.id})"><i class="fas fa-unlock"></i></button>` : ""}<button class="btn btn-r btn-xs" onclick="delUser(${row.id})"><i class="fas fa-trash"></i></button></div></td>
    </tr>`).join("");
  }

  window.loadUsers = loadUsers;
  window.openUserModal = function openUserModal(id, data = null) {
    $("ueid").value = id || "";
    $("uusr").value = data?.username || "";
    $("ufn").value = data?.full_name || "";
    $("unpas").value = "";
    $("urol").value = data?.role || "viewer";
    $("uact").checked = data ? !!parseInt(data.is_active, 10) : true;
    $("urot").checked = data ? !!parseInt(data.must_change_password, 10) : true;
    $("uclr").value = data?.avatar_color || "#a855f7";
    selectColor($("uclr").value);
    $("musertitle").innerHTML = id ? '<i class="fas fa-user-edit"></i> Modifier' : '<i class="fas fa-user-plus"></i> Ajouter';
    $("passreqlbl").textContent = id ? "(vide = inchangé)" : "*";
    openM("muser");
  };

  function selectColor(color) {
    $("uclr").value = color;
    document.querySelectorAll("#cpick span").forEach((el) => el.classList.toggle("sel", el.dataset.c === color));
  }

  window.selColor = selectColor;

  window.subUser = async function subUser() {
    const payload = {
      id: parseInt($("ueid").value, 10) || 0,
      username: $("uusr").value.trim(),
      full_name: $("ufn").value.trim(),
      role: $("urol").value,
      new_pass: $("unpas").value,
      is_active: $("uact").checked ? 1 : 0,
      must_change_password: $("urot").checked ? 1 : 0,
      avatar_color: $("uclr").value,
      csrf_token: csrf,
    };
    if (!payload.username) {
      toast("Identifiant requis", "error");
      return;
    }
    if (!payload.id && !payload.new_pass) {
      toast("Mot de passe requis", "error");
      return;
    }
    const response = await fetch(apiUrl("user_save"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (data.ok) {
      toast(data.msg, "success");
      closeM("muser");
      loadUsers();
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  window.delUser = async function delUser(id) {
    const ok = await confirmAction(`Supprimer l'utilisateur #${id} ?`);
    if (!ok) return;
    const response = await fetch(apiUrl("user_delete", { id, csrf_token: csrf }));
    const data = await response.json();
    if (data.ok) {
      toast("Utilisateur supprimé", "warn");
      loadUsers();
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  window.unlockUser = async function unlockUser(id) {
    const response = await fetch(apiUrl("user_unlock", { id, csrf_token: csrf }));
    const data = await response.json();
    if (data.ok) {
      toast("Compte débloqué", "success");
      loadUsers();
    } else {
      toast(data.msg || "Erreur", "error");
    }
  };

  async function loadAudit() {
    const response = await fetch(apiUrl("audit_log"));
    const data = await response.json();
    const tbody = $("audittb");
    if (!Array.isArray(data) || !data.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">Aucune entrée d\'audit</td></tr>';
      return;
    }
    tbody.innerHTML = data.map((row) => `<tr><td style="color:var(--muted);font-size:10px">#${row.id}</td><td><strong style="font-size:11px">${escapeHtml(row.full_name || row.username || "?")}</strong></td><td><span style="font-family:var(--fh);font-size:10px;font-weight:900;color:var(--cyan)">${escapeHtml(row.action)}</span></td><td style="font-size:10px;max-width:200px">${escapeHtml(truncate(row.details || "", 80))}</td><td style="font-size:10px;color:var(--muted)">${escapeHtml(row.ip_address || "?")}</td><td>${row.requires_confirm ? '<span style="color:var(--red);font-size:10px">Oui</span>' : '<span style="color:var(--muted);font-size:10px">Non</span>'}</td><td style="font-size:10px;color:var(--muted)">${escapeHtml((row.created_at || "").slice(0, 16))}</td></tr>`).join("");
  }

  window.loadAudit = loadAudit;

  window.runDiag = async function runDiag() {
    const pre = $("diagout");
    if (pre) pre.textContent = "Diagnostic en cours…";
    try {
      const response = await fetch(apiUrl("diag"));
      const data = await response.json();
      if (pre) pre.textContent = JSON.stringify(data, null, 2);
      toast("Diagnostic OK", "info");
    } catch (error) {
      if (pre) pre.textContent = "Erreur: " + error.message;
      toast("Erreur diag", "error");
    }
  };

  window.reindexSite = async function reindexSite() {
    const response = await fetch(apiUrl("reindex_site", { csrf_token: csrf }));
    const data = await response.json();
    if (data.ok) {
      const ksite = $("ksite");
      if (ksite) ksite.textContent = String(data.count || 0);
      toast(data.msg, "success");
    } else {
      toast(data.msg || "Erreur de réindexation", "error");
    }
  };

  window.printChat = function printChat() {
    window.print();
  };

  window.exportConv = function exportConv() {
    const msgs = $("msgs");
    let txt = "H²O AI — Export conversation\n" + new Date().toLocaleString("fr-FR") + "\n" + "=".repeat(50) + "\n\n";
    msgs.querySelectorAll(".msg").forEach((msg) => {
      const role = msg.classList.contains("user") ? "VOUS" : "H²O AI";
      const text = msg.querySelector(".bbl")?.innerText || "";
      const time = msg.querySelector(".mtime")?.textContent || "";
      txt += `[${time}] ${role}:\n${text}\n\n`;
    });
    const link = document.createElement("a");
    link.href = "data:text/plain;charset=utf-8," + encodeURIComponent(txt);
    link.download = "conversation_" + new Date().toISOString().slice(0, 10) + ".txt";
    link.click();
    toast("Conversation exportée", "success");
  };

  window.clrChat = function clrChat() {
    $("msgs").innerHTML = `<div class="msg agent"><div class="bbl">🔄 Chat effacé. Comment puis-je vous aider ?</div><span class="mtime">${now()}</span></div>`;
    $("qgrid").style.display = "flex";
  };

  window.togglePass = function togglePass() {
    const input = $("lpass");
    const icon = $("eyetog");
    if (!input || !icon) return;
    input.type = input.type === "password" ? "text" : "password";
    icon.className = "fas " + (input.type === "text" ? "fa-eye-slash" : "fa-eye") + " ico";
  };

  document.addEventListener("click", (event) => {
    if (!event.target.closest(".irow")) $("acd") && ($("acd").style.display = "none");
    if (event.target.matches(".modal")) event.target.classList.remove("show");
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      document.querySelectorAll(".modal.show").forEach((modal) => modal.classList.remove("show"));
      closeConf(false);
    }
  });

  $("confOk")?.addEventListener("click", () => closeConf(true));

  (function tick() {
    const nowDate = new Date();
    if ($("clk")) $("clk").textContent = nowDate.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    if ($("clkd")) $("clkd").textContent = nowDate.toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
    if ($("clk2")) $("clk2").textContent = nowDate.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    setTimeout(tick, 1000);
  })();

  setTimeout(() => toast("H²O AI prêt", "success", "RBAC · Search v2 · Analytics"), 600);
  if (mustChangePassword) {
    setTimeout(() => openM("mprofil"), 900);
    toast("Rotation du mot de passe requise", "warn");
  }
})();
