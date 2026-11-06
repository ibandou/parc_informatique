/* ==========================================================================
   APP.JS - Comportements de l'interface (les pages sont générées par PHP) :
   sidebar, menus déroulants, thème, modales, toasts, confirmations, filtres,
   recherche globale et éditeur de texte enrichi.
   ========================================================================== */

(function () {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function escapeHtml(str) {
    return String(str ?? "").replace(/[&<>"']/g, s => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[s]));
  }

  /* ------------------------------ SIDEBAR ------------------------------ */
  const sidebar = $("#sidebar");
  const collapseBtn = $("#sidebar-collapse-btn");

  function setCollapsed(collapsed) {
    sidebar.classList.toggle("collapsed", collapsed);
    collapseBtn.querySelector("i").className = collapsed ? "fa-solid fa-angles-right" : "fa-solid fa-angles-left";
    collapseBtn.title = collapsed ? "Déployer le menu" : "Rétracter le menu";
  }

  if (sidebar && collapseBtn) {
    // L'état rétracté est mémorisé dans le navigateur (simple confort d'affichage)
    try { if (localStorage.getItem("itcorp_sidebar_collapsed") === "1") setCollapsed(true); } catch (e) {}
    collapseBtn.addEventListener("click", () => {
      const collapsed = !sidebar.classList.contains("collapsed");
      setCollapsed(collapsed);
      try { localStorage.setItem("itcorp_sidebar_collapsed", collapsed ? "1" : "0"); } catch (e) {}
    });

    const overlay = $("#sidebar-overlay");
    const closeMobile = () => { sidebar.classList.remove("mobile-open"); overlay.classList.remove("active"); };
    $("#mobile-menu-btn").addEventListener("click", () => { sidebar.classList.add("mobile-open"); overlay.classList.add("active"); });
    overlay.addEventListener("click", closeMobile);

    // Chaque page recharge la sidebar en haut : on fait défiler le menu jusqu'à l'élément actif
    const nav = $("#sidebar-nav");
    const activeItem = $(".nav-subitem.active", nav) || $(".nav-item.active", nav);
    const revealActive = () => {
      const navRect = nav.getBoundingClientRect();
      const itemRect = activeItem.getBoundingClientRect();
      if (itemRect.top < navRect.top || itemRect.bottom > navRect.bottom) {
        nav.scrollTop += itemRect.top - navRect.top - (nav.clientHeight - itemRect.height) / 2;
      }
    };
    if (nav && activeItem) {
      revealActive();
      // Les polices et icônes chargées ensuite peuvent décaler le menu : on recentre une fois la page prête
      window.addEventListener("load", revealActive);
    }

    // Page à sous-menu : la flèche, ou un clic sur la page déjà affichée, replie / déplie le sous-menu
    $$(".nav-item[data-page]").forEach(item => {
      const sub = $(`.nav-sub[data-parent="${item.dataset.page}"]`);
      if (!sub) return;
      item.addEventListener("click", (e) => {
        if (e.target.closest(".nav-caret") || item.classList.contains("active")) {
          e.preventDefault();
          const open = !sub.classList.contains("open");
          sub.classList.toggle("open", open);
          item.classList.toggle("expanded", open);
        }
      });
    });
  }

  /* --------------------------- MENUS DÉROULANTS --------------------------- */
  $$("[data-dropdown]").forEach(btn => {
    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      const panel = document.getElementById(btn.dataset.dropdown);
      const willOpen = panel.hidden;
      $$(".dropdown-panel").forEach(p => p.hidden = true);
      panel.hidden = !willOpen;
    });
  });
  $$(".dropdown-panel").forEach(p => p.addEventListener("click", e => e.stopPropagation()));
  document.addEventListener("click", () => {
    $$(".dropdown-panel").forEach(p => p.hidden = true);
    const results = $("#search-results");
    if (results) results.hidden = true;
  });

  /* -------------------------------- THÈME -------------------------------- */
  const themeBtn = $("#theme-toggle-btn");
  if (themeBtn) {
    themeBtn.addEventListener("click", () => {
      const next = document.documentElement.getAttribute("data-theme") === "dark" ? "light" : "dark";
      document.documentElement.setAttribute("data-theme", next);
      themeBtn.querySelector("i").className = next === "dark" ? "fa-solid fa-sun" : "fa-solid fa-moon";
      document.cookie = `itcorp_theme=${next}; path=/; max-age=31536000; samesite=lax`;
    });
  }

  /* ------------------------------- MODALES ------------------------------- */
  // Les modales sont rendues par PHP (paramètre "modal" dans l'URL) : les fermer revient
  // à afficher l'URL indiquée par data-close-url. Les modales créées en JavaScript
  // (confirmation) sont simplement retirées du DOM.
  function closeTopModal() {
    const overlays = $$(".modal-overlay");
    const top = overlays[overlays.length - 1];
    if (!top) return false;
    if (top.dataset.closeUrl) window.location.href = top.dataset.closeUrl;
    else top.remove();
    return true;
  }

  document.addEventListener("mousedown", (e) => {
    if (e.target.classList && e.target.classList.contains("modal-overlay")) {
      if (e.target.dataset.closeUrl) window.location.href = e.target.dataset.closeUrl;
      else e.target.remove();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const openPanels = $$(".dropdown-panel").filter(p => !p.hidden);
    if (openPanels.length) { openPanels.forEach(p => p.hidden = true); return; }
    closeTopModal();
  });

  function openJsModal(innerHtml, size = "") {
    const overlay = document.createElement("div");
    overlay.className = "modal-overlay";
    overlay.innerHTML = `<div class="modal-box ${size}">${innerHtml}</div>`;
    $("#modal-root").appendChild(overlay);
    overlay.querySelectorAll("[data-modal-close]").forEach(b => b.addEventListener("click", () => overlay.remove()));
    return overlay;
  }

  // Onglets (fiche équipement...)
  $$(".tabs[data-tabs]").forEach(tabs => {
    const scope = tabs.parentElement;
    tabs.querySelectorAll(".tab-btn[data-tab]").forEach(btn => {
      btn.addEventListener("click", () => {
        tabs.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        scope.querySelectorAll(".tab-panel").forEach(p => p.classList.remove("active"));
        btn.classList.add("active");
        document.getElementById(btn.dataset.tab).classList.add("active");
      });
    });
  });

  // Lignes et éléments cliquables : <tr data-href="...">
  $$("[data-href]").forEach(el => {
    el.addEventListener("click", (e) => {
      if (e.target.closest("a, button, form")) return;
      window.location.href = el.dataset.href;
    });
  });

  /* -------------------------------- TOASTS -------------------------------- */
  $$("#toast-container .toast").forEach((toast, i) => {
    setTimeout(() => {
      toast.style.transition = "opacity .25s";
      toast.style.opacity = "0";
      setTimeout(() => toast.remove(), 250);
    }, 3600 + i * 300);
  });

  function showToast(message, type = "info") {
    const icons = { success: "fa-circle-check", error: "fa-circle-exclamation", warning: "fa-triangle-exclamation", info: "fa-circle-info" };
    const toast = document.createElement("div");
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i><span>${escapeHtml(message)}</span>`;
    $("#toast-container").appendChild(toast);
    setTimeout(() => { toast.style.transition = "opacity .25s"; toast.style.opacity = "0"; setTimeout(() => toast.remove(), 250); }, 3600);
  }

  /* ---------------------------- CONFIRMATIONS ---------------------------- */
  // <form data-confirm-title="..." data-confirm-message="..."> : demande confirmation avant envoi
  $$("form[data-confirm-title]").forEach(form => {
    form.addEventListener("submit", (e) => {
      if (form.dataset.confirmed) return;
      e.preventDefault();
      const overlay = openJsModal(`
        <div class="modal-header"><h2><i class="fa-solid fa-circle-question"></i> ${escapeHtml(form.dataset.confirmTitle)}</h2><button type="button" class="icon-btn" data-modal-close><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal-body"><p>${escapeHtml(form.dataset.confirmMessage)}</p></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
          <button type="button" class="btn btn-danger" id="confirm-dialog-btn">Confirmer</button>
        </div>`, "modal-sm");
      overlay.querySelector("#confirm-dialog-btn").addEventListener("click", () => {
        overlay.remove();
        form.dataset.confirmed = "1";
        form.submit();
      });
    });
  });

  /* ----------------------- FILTRES À ENVOI AUTOMATIQUE ----------------------- */
  // Les listes sont filtrées côté serveur : un changement de filtre recharge la page
  // (retour à la page 1), la recherche texte après une courte pause de saisie.
  $$("form[data-autosubmit]").forEach(form => {
    form.querySelectorAll("select").forEach(sel => sel.addEventListener("change", () => form.submit()));
    let timer = null;
    form.querySelectorAll('input[type="text"], input[type="search"]').forEach(input => {
      input.addEventListener("input", () => { clearTimeout(timer); timer = setTimeout(() => form.submit(), 450); });
      // Après rechargement, le curseur revient en fin de saisie
      if (input.dataset.refocus !== undefined) {
        input.focus();
        const len = input.value.length;
        input.setSelectionRange(len, len);
      }
    });
  });

  /* ------------------------- AFFICHER LE MOT DE PASSE ------------------------- */
  $$("[data-toggle-password]").forEach(btn => {
    btn.addEventListener("click", () => {
      const input = document.getElementById(btn.dataset.togglePassword);
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      btn.querySelector("i").className = show ? "fa-solid fa-eye-slash" : "fa-solid fa-eye";
      const label = show ? "Masquer le mot de passe" : "Afficher le mot de passe";
      btn.setAttribute("aria-label", label);
      btn.title = label;
      input.focus();
    });
  });

  /* ----------------- CASE À COCHER QUI BASCULE DEUX BLOCS ----------------- */
  // <input type="checkbox" data-toggle-show="#a" data-toggle-hide="#b">
  $$("input[type=checkbox][data-toggle-show]").forEach(cb => {
    const apply = () => {
      const show = document.querySelector(cb.dataset.toggleShow);
      const hide = document.querySelector(cb.dataset.toggleHide);
      show.style.display = cb.checked ? "" : "none";
      hide.style.display = cb.checked ? "none" : "";
      hide.querySelectorAll("select, input").forEach(el => el.required = !cb.checked && el.dataset.required !== undefined);
    };
    cb.addEventListener("change", apply);
    apply();
  });

  /* ------------------ N° D'INVENTAIRE AUTOMATIQUE (ÉQUIPEMENT) ------------------ */
  // Préfixe de la catégorie, ou pour le type "Autre" les 3 premières lettres du nom de
  // l'objet (même calcul que prefix_from_name() en PHP), puis plus grand numéro + 1.
  const prefixFromName = (name) => {
    const letters = (name || "").trim().toLowerCase().replace(/œ/g, "oe").replace(/æ/g, "ae")
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z]/g, "").toUpperCase();
    return letters.length >= 2 ? letters.slice(0, 3) + "-" : null;
  };
  const prefixOf = (numero) => { const m = /^(.*?)(\d+)$/.exec(numero || ""); return m ? m[1] : numero; };
  $$("select[data-inventaire]").forEach(sel => {
    const data = JSON.parse(sel.dataset.inventaire);
    const target = document.querySelector(sel.dataset.inventaireTarget);
    const nomInput = document.querySelector(sel.dataset.nomObjet);
    const nomRow = nomInput && nomInput.closest(".form-row");
    const { inventaireKeepFor: keepFor, inventaireKeepValue: keepValue, inventaireKeepAny: keepAny } = sel.dataset;
    const refresh = () => {
      const catPrefix = data.prefixes[sel.value];
      const isAutre = catPrefix === data.autre;
      if (nomRow) { nomRow.hidden = !isAutre; nomInput.required = isAutre; }
      if (!catPrefix) { target.value = ""; return; }
      const prefix = isAutre ? (prefixFromName(nomInput && nomInput.value) || data.autre) : catPrefix;
      if (keepFor && sel.value === keepFor && (keepAny || prefixOf(keepValue) === prefix)) { target.value = keepValue; return; }
      target.value = prefix + String((data.counters[prefix] || 0) + 1).padStart(2, "0");
    };
    sel.addEventListener("change", refresh);
    if (nomInput) nomInput.addEventListener("input", refresh);
    refresh();
  });

  /* ---------------------------- RECHERCHE GLOBALE ---------------------------- */
  const searchInput = $("#global-search");
  const searchBox = $("#search-results");
  if (searchInput && searchBox) {
    let timer = null;
    searchInput.addEventListener("click", e => e.stopPropagation());
    searchBox.addEventListener("click", e => e.stopPropagation());
    searchInput.addEventListener("input", () => {
      clearTimeout(timer);
      const q = searchInput.value.trim();
      if (q.length < 2) { searchBox.hidden = true; searchBox.innerHTML = ""; return; }
      timer = setTimeout(() => {
        fetch("search.php?q=" + encodeURIComponent(q), { credentials: "same-origin" })
          .then(r => r.json())
          .then(results => {
            let html = "", group = "";
            results.forEach(r => {
              if (r.group !== group) { group = r.group; html += `<div class="search-group-label">${escapeHtml(group)}</div>`; }
              html += `<a class="search-result-item" href="${escapeHtml(r.url)}"><i class="fa-solid ${escapeHtml(r.icon)}"></i> <span>${escapeHtml(r.label)}</span></a>`;
            });
            searchBox.innerHTML = html || `<div class="search-empty">Aucun résultat pour "${escapeHtml(q)}"</div>`;
            searchBox.hidden = false;
          })
          .catch(() => {});
      }, 200);
    });
  }

  /* ------------------------- ÉDITEUR DE TEXTE ENRICHI ------------------------- */
  // .richtext[data-input="nom"] : le contenu est recopié dans le champ caché à l'envoi
  $$(".richtext[data-input]").forEach(rt => {
    const editor = rt.querySelector(".richtext-editor");
    const form = rt.closest("form");
    const hidden = form.querySelector(`input[name="${rt.dataset.input}"]`);
    rt.querySelectorAll("[data-rt-cmd]").forEach(btn => {
      // mousedown + preventDefault : garde la sélection dans l'éditeur
      btn.addEventListener("mousedown", e => e.preventDefault());
      btn.addEventListener("click", () => { editor.focus(); document.execCommand(btn.dataset.rtCmd, false, null); });
    });
    // Collage toujours en texte brut (jamais le HTML d'une source externe)
    editor.addEventListener("paste", (e) => {
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData("text/plain");
      document.execCommand("insertText", false, text);
    });
    form.addEventListener("submit", (e) => {
      if (!editor.textContent.trim()) { e.preventDefault(); showToast("Veuillez saisir un commentaire.", "error"); return; }
      hidden.value = editor.innerHTML;
    });
  });

  /* ------------------------- IMPRESSION D'UN RAPPORT ------------------------- */
  const printArea = $("#print-area[data-autoprint]");
  if (printArea) {
    showToast("Génération du rapport en cours... la boîte d'impression va s'ouvrir.", "info");
    setTimeout(() => window.print(), 400);
  }
})();
