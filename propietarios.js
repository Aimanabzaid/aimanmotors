/*
 * Aiman Motors — funciones para propietarios (Fase 1)
 * Simulador de ingresos · Preevaluación con calendario · Actualizar disponibilidad · Recomendaciones
 *
 * Usa utilidades definidas en index.html: wa(), compress(), setStatus(), updateFiles(), MAX_FOTOS.
 */

/* ===== Configuración editable =====
 * Los valores del simulador son SOLO DE EJEMPLO: no son comisiones acordadas.
 * Cambia "recompensa" cuando las condiciones del programa estén definidas
 * (déjalo vacío para no prometer nada).
 */
const CONFIG_PROPIETARIOS = {
  simulador: { precioDia: 35, diasMes: 12, porcentajeAiman: 40 },
  referidos: { recompensa: "" },
  calendario: { mesesVista: 6 }
};

(function () {
  "use strict";

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const eur = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR", minimumFractionDigits: 0, maximumFractionDigits: 2 });
  const store = {
    get(k) { try { return sessionStorage.getItem(k) || localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v, persist) { try { (persist ? localStorage : sessionStorage).setItem(k, v); } catch (e) {} }
  };

  /* ================= SIMULADOR ================= */
  function initSimulador() {
    const root = $("#simulador");
    if (!root) return;
    const cfg = CONFIG_PROPIETARIOS.simulador;
    const pairs = {
      precio: [$("#simPrecio"), $("#simPrecioN")],
      dias: [$("#simDias"), $("#simDiasN")],
      aiman: [$("#simAiman"), $("#simAimanN")],
      prop: [$("#simProp"), $("#simPropN")]
    };
    const out = k => root.querySelector('[data-out="' + k + '"]');
    const state = { precio: cfg.precioDia, dias: cfg.diasMes, aiman: cfg.porcentajeAiman };

    const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
    function setPair(k, v) { pairs[k].forEach(el => { if (document.activeElement !== el) el.value = v; }); }

    function render() {
      const prop = 100 - state.aiman;
      setPair("precio", state.precio); setPair("dias", state.dias);
      setPair("aiman", state.aiman); setPair("prop", prop);
      const bruto = state.precio * state.dias;
      const comision = bruto * state.aiman / 100;
      const propietario = bruto - comision;
      out("owner").textContent = eur.format(propietario);
      out("owner2").textContent = eur.format(propietario);
      out("gross").textContent = eur.format(bruto);
      out("am").textContent = eur.format(comision);
      out("pa").textContent = "(" + state.aiman + " %)";
      out("pp").textContent = "(" + prop + " %)";
      out("formula").textContent = eur.format(state.precio) + " × " + state.dias + (state.dias === 1 ? " día" : " días") + " = " + eur.format(bruto) + " brutos al mes";
      $(".b-owner", root).style.width = prop + "%";
      $(".b-am", root).style.width = state.aiman + "%";
    }

    function bind(k, apply) {
      pairs[k].forEach(el => {
        el.addEventListener("input", () => {
          const n = parseFloat(String(el.value).replace(",", "."));
          if (isNaN(n)) return;
          apply(n); render();
        });
        el.addEventListener("blur", render);
      });
    }
    let usado = false;
    root.addEventListener("input", () => { if (!usado && window.amEvento) { usado = true; amEvento("uso_simulador"); } });
    bind("precio", n => { state.precio = clamp(Math.round(n * 100) / 100, 0, 1000); });
    bind("dias", n => { state.dias = clamp(Math.round(n), 0, 31); });
    bind("aiman", n => { state.aiman = clamp(Math.round(n), 0, 100); });
    bind("prop", n => { state.aiman = 100 - clamp(Math.round(n), 0, 100); });
    render();
  }

  /* ================= CALENDARIO ================= */
  const MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
  const key = d => d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");

  function Calendario(root) {
    const estado = new Map(); // "AAAA-MM-DD" -> "on" | "off"
    const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
    const primero = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    const meses = CONFIG_PROPIETARIOS.calendario.mesesVista;
    let vista = 0;
    let modo = "on";
    const grid = $(".cal-grid", root);

    function mesActual() { return new Date(primero.getFullYear(), primero.getMonth() + vista, 1); }
    function diasDelMes() {
      const m = mesActual(), out = [];
      for (let d = new Date(m); d.getMonth() === m.getMonth(); d.setDate(d.getDate() + 1)) out.push(new Date(d));
      return out;
    }

    function render() {
      const m = mesActual();
      $(".cal-title", root).textContent = MESES[m.getMonth()] + " " + m.getFullYear();
      $("[data-prev]", root).disabled = vista === 0;
      $("[data-next]", root).disabled = vista >= meses - 1;
      grid.innerHTML = "";
      const hueco = (m.getDay() + 6) % 7; // lunes primero
      for (let i = 0; i < hueco; i++) { const e = document.createElement("span"); e.className = "cal-day empty"; grid.appendChild(e); }
      diasDelMes().forEach(d => {
        const b = document.createElement("button");
        const k = key(d), st = estado.get(k);
        b.type = "button";
        b.className = "cal-day" + (st ? " " + st : "") + (d.getTime() === hoy.getTime() ? " today" : "");
        b.textContent = d.getDate();
        b.dataset.k = k;
        b.disabled = d < hoy;
        b.setAttribute("aria-label", d.getDate() + " de " + MESES[d.getMonth()] + ": " + (st === "on" ? "disponible" : st === "off" ? "no disponible" : "sin marcar"));
        b.setAttribute("aria-pressed", st ? "true" : "false");
        grid.appendChild(b);
      });
      resumen();
    }
    function resumen() {
      let on = 0, off = 0;
      estado.forEach(v => v === "on" ? on++ : off++);
      $(".cal-summary", root).textContent = on || off
        ? on + (on === 1 ? " día disponible" : " días disponibles") + " · " + off + (off === 1 ? " no disponible" : " no disponibles")
        : "Toca los días para marcarlos. Vuelve a tocar para desmarcar.";
    }

    grid.addEventListener("click", e => {
      const b = e.target.closest(".cal-day[data-k]");
      if (!b || b.disabled) return;
      const k = b.dataset.k;
      if (estado.get(k) === modo) estado.delete(k); else estado.set(k, modo);
      render();
      const again = grid.querySelector('[data-k="' + k + '"]');
      if (again) again.focus();
    });
    $$(".cal-mode", root).forEach(btn => btn.addEventListener("click", () => {
      modo = btn.dataset.mode;
      $$(".cal-mode", root).forEach(x => { const a = x === btn; x.classList.toggle("active", a); x.setAttribute("aria-pressed", a); });
    }));
    $("[data-prev]", root).addEventListener("click", () => { if (vista > 0) { vista--; render(); } });
    $("[data-next]", root).addEventListener("click", () => { if (vista < meses - 1) { vista++; render(); } });
    $$("[data-fill]", root).forEach(btn => btn.addEventListener("click", () => {
      const tipo = btn.dataset.fill;
      diasDelMes().forEach(d => {
        if (d < hoy) return;
        const k = key(d);
        if (tipo === "clear") estado.delete(k);
        else if (tipo === "month" || d.getDay() === 0 || d.getDay() === 6) estado.set(k, modo);
      });
      render();
    }));

    render();
    return {
      datos() {
        const on = [], off = [];
        estado.forEach((v, k) => (v === "on" ? on : off).push(k));
        return { on: on.sort(), off: off.sort() };
      },
      limpiar() { estado.clear(); vista = 0; render(); }
    };
  }

  /* ================= REFERIDOS: llegada con ?ref= ================= */
  function refActual() {
    const p = new URLSearchParams(location.search).get("ref");
    if (p && /^R-[A-Z2-9]{6}$/i.test(p)) { store.set("aiman_ref", p.toUpperCase()); return p.toUpperCase(); }
    return store.get("aiman_ref") || "";
  }
  function initLlegadaReferido(ref) {
    if (!ref) return;
    const toast = $("#refToast");
    let visto = false;
    try { visto = sessionStorage.getItem("aiman_ref_toast") === ref; } catch (e) {}
    if (toast && !visto) {
      $("[data-toast-code]", toast).textContent = ref;
      toast.hidden = false;
      $("[data-toast-close]", toast).addEventListener("click", () => { toast.hidden = true; try { sessionStorage.setItem("aiman_ref_toast", ref); } catch (e) {} });
      setTimeout(() => { toast.hidden = true; }, 12000);
    }
  }

  /* ================= PREEVALUACIÓN ================= */
  function initPreevaluacion(ref) {
    const modal = $("#ownerModal"), form = $("#ownerForm");
    if (!form) return;
    const pasos = $$(".wz-step", form);
    const cal = Calendario($("#ownerCal"));
    const prev = $("[data-wz-prev]", form), next = $("[data-wz-next]", form), submit = $("[data-wz-submit]", form);
    const okView = $(".form-ok", modal);
    let paso = 0, ultimaRef = "";

    form.elements.ref.value = ref || "";
    const nota = $("[data-ref-note]", form);
    if (ref && nota) { nota.textContent = "Llegas recomendado por el código " + ref + ". Quedará registrado en tu solicitud."; nota.hidden = false; }

    function mostrar(i) {
      paso = i;
      pasos.forEach((s, j) => { s.hidden = j !== i; });
      $(".wz-count", form).textContent = "Paso " + (i + 1) + " de " + pasos.length;
      $(".wz-name", form).textContent = pasos[i].dataset.name;
      $(".wz-bar i", form).style.width = ((i + 1) / pasos.length * 100) + "%";
      prev.hidden = i === 0;
      next.hidden = i === pasos.length - 1;
      submit.hidden = i !== pasos.length - 1;
      setStatus(form, "");
      const card = form.closest(".modal-card"); if (card) card.scrollTop = 0;
    }
    function validarPaso(i) {
      const s = pasos[i];
      for (const el of $$("input,select,textarea", s)) {
        const oculto = el.closest("[hidden]");
        if ((oculto && oculto !== s) || el.type === "hidden" || el.type === "file") continue;
        if (!el.checkValidity()) {
          if (el.type === "radio") {
            setStatus(form, "Responde a todas las preguntas para continuar.", "error");
            el.closest(".q").scrollIntoView({ block: "center", behavior: "smooth" });
          } else { el.reportValidity(); }
          return false;
        }
      }
      return true;
    }

    next.addEventListener("click", () => { if (validarPaso(paso)) mostrar(paso + 1); });
    prev.addEventListener("click", () => mostrar(paso - 1));

    // Averías: pedir detalle solo si responde "Sí"
    const detalle = $("[data-danos-detalle]", form), detalleTxt = $("#oDanos");
    $$('input[name="danos"]', form).forEach(r => r.addEventListener("change", () => {
      const si = form.elements.danos.value === "Sí";
      detalle.hidden = !si; detalleTxt.required = si;
    }));
    $("#oFotos").addEventListener("change", () => updateFiles(form));

    // Reiniciar al abrir (si ya se envió una solicitud)
    $$('[data-open-modal="ownerModal"]').forEach(b => b.addEventListener("click", () => {
      form.dataset.t = Date.now();
      if (!okView.hidden) {
        okView.hidden = true; form.hidden = false; form.reset(); cal.limpiar(); updateFiles(form);
        detalle.hidden = true; detalleTxt.required = false; form.elements.ref.value = ref || "";
        mostrar(0);
      }
    }));

    function resumenWa(conRef) {
      const f = form.elements, d = cal.datos();
      const l = ["Hola Aiman Motors, quiero que evaluéis mi coche para alquilarlo."];
      if (conRef) l.push("Referencia: " + conRef);
      l.push("");
      const add = (t, v) => l.push(t + ": " + (v && String(v).trim() ? String(v).trim() : "Sin especificar"));
      add("Nombre", f.nombre.value); add("Teléfono", f.telefono.value);
      add("Coche", (f.marca.value + " " + f.modelo.value).trim()); add("Año", f.ano.value); add("Kilometraje", f.km.value);
      add("Ciudad", f.ciudad.value); add("Combustible", f.combustible.value); add("Estado", f.estado.value);
      add("ITV en vigor", f.itv.value); add("Seguro vigente", f.seguro.value); add("Averías o daños", f.danos.value);
      if (f.danos.value === "Sí") add("Detalle", f.danos_detalle.value);
      add("Disponibilidad", f.disponibilidad.value);
      if (d.on.length) add("Días disponibles marcados", d.on.length);
      if (f.ref.value) add("Recomendado por", f.ref.value);
      if (!conRef && $("#oFotos").files.length) l.push("", "(Te mando las fotos por aquí.)");
      return l.join("\n");
    }
    $("[data-wz-wa]", form).addEventListener("click", () => wa(resumenWa("")));
    $("[data-ok-wa]", modal).addEventListener("click", () => wa(resumenWa(ultimaRef)));

    form.addEventListener("submit", async e => {
      e.preventDefault();
      for (let i = 0; i < pasos.length; i++) {
        if (!validarPaso(i)) { if (i !== paso) { mostrar(i); validarPaso(i); } return; }
      }
      const fd = new FormData(form);
      fd.set("t", form.dataset.t || "0");
      const d = cal.datos();
      fd.set("dias", d.on.join(",")); fd.set("dias_no", d.off.join(","));
      fd.delete("fotos[]");
      const files = [...$("#oFotos").files].slice(0, MAX_FOTOS);
      if (files.length) setStatus(form, "Preparando fotos…");
      for (let i = 0; i < files.length; i++) {
        const o = await compress(files[i]);
        if (o) fd.append("fotos[]", o, "foto-" + (i + 1) + (o === files[i] ? "" : ".jpg"));
      }
      submit.disabled = true; submit.textContent = "ENVIANDO…";
      setStatus(form, "Enviando tu solicitud…");
      try {
        const res = await fetch("enviar.php", { method: "POST", body: fd });
        const data = await res.json().catch(() => ({ ok: false }));
        if (data.ok) {
          ultimaRef = data.referencia || "";
          $("[data-ok-ref]", modal).textContent = ultimaRef;
          store.set("aiman_ultima_ref", ultimaRef, true);
          store.set("aiman_ultimo_tel", form.elements.telefono.value, true);
          form.hidden = true; okView.hidden = false;
          if (window.amEvento) amEvento("envio_preevaluacion");
          const card = form.closest(".modal-card"); if (card) card.scrollTop = 0;
        } else {
          setStatus(form, (data.mensaje || "No se ha podido enviar.") + " También puedes enviarlo por WhatsApp.", "error");
        }
      } catch (err) {
        setStatus(form, "No hay conexión ahora mismo. Prueba de nuevo o envíalo por WhatsApp.", "error");
      } finally {
        submit.disabled = false; submit.textContent = "ENVIAR SOLICITUD";
      }
    });

    mostrar(0);
  }

  /* ================= ACTUALIZAR DISPONIBILIDAD ================= */
  function initDisponibilidad() {
    const modal = $("#dispModal"), form = $("#dispForm");
    if (!form) return;
    const cal = Calendario($("#dispCal"));
    const okView = $(".form-ok", modal);
    const btn = $(".modal-submit", form);

    $$('[data-open-modal="dispModal"]').forEach(b => b.addEventListener("click", () => {
      form.dataset.t = Date.now();
      if (!okView.hidden) { okView.hidden = true; form.hidden = false; cal.limpiar(); }
      if (!form.elements.referencia.value) form.elements.referencia.value = store.get("aiman_ultima_ref") || "";
      if (!form.elements.telefono.value) form.elements.telefono.value = store.get("aiman_ultimo_tel") || "";
      setStatus(form, "");
    }));
    form.elements.referencia.addEventListener("input", e => { e.target.value = e.target.value.toUpperCase(); });

    form.addEventListener("submit", async e => {
      e.preventDefault();
      if (!form.reportValidity()) return;
      const d = cal.datos();
      if (!d.on.length && !d.off.length) { setStatus(form, "Marca al menos un día en el calendario.", "error"); return; }
      const fd = new FormData(form);
      fd.set("t", form.dataset.t || "0");
      fd.set("dias", d.on.join(",")); fd.set("dias_no", d.off.join(","));
      btn.disabled = true; btn.textContent = "GUARDANDO…";
      try {
        const res = await fetch("enviar.php", { method: "POST", body: fd });
        const data = await res.json().catch(() => ({ ok: false }));
        if (data.ok) { form.hidden = true; okView.hidden = false; if (window.amEvento) amEvento("envio_disponibilidad"); }
        else setStatus(form, data.mensaje || "No se ha podido guardar.", "error");
      } catch (err) {
        setStatus(form, "No hay conexión ahora mismo. Prueba de nuevo.", "error");
      } finally {
        btn.disabled = false; btn.textContent = "GUARDAR DISPONIBILIDAD";
      }
    });
  }

  /* ================= RECOMENDACIONES ================= */
  function initRecomendaciones() {
    const root = $("#recomienda"), form = $("#refForm");
    if (!form) return;
    const recompensa = CONFIG_PROPIETARIOS.referidos.recompensa;
    $("[data-reward]", root).textContent = recompensa
      ? recompensa
      : "Estamos definiendo las ventajas del programa. Te las contaremos antes de aplicarlas.";
    form.dataset.t = Date.now();
    const sol = store.get("aiman_ultima_ref");
    if (sol) form.elements.solicitud.value = sol;
    form.elements.solicitud.addEventListener("input", e => { e.target.value = e.target.value.toUpperCase(); });

    const res = $(".ref-result", root);
    let enlace = "";
    form.addEventListener("submit", async e => {
      e.preventDefault();
      if (!form.reportValidity()) return;
      const btn = $(".modal-submit", form);
      const fd = new FormData(form);
      fd.set("t", form.dataset.t || "0");
      btn.disabled = true; btn.textContent = "CREANDO…";
      try {
        const r = await fetch("enviar.php", { method: "POST", body: fd });
        const data = await r.json().catch(() => ({ ok: false }));
        if (data.ok && data.codigo) {
          enlace = data.enlace;
          $("[data-ref-code]", res).textContent = data.codigo;
          $("[data-ref-link]", res).value = enlace;
          $("[data-ref-msg]", res).textContent = data.mensaje === "Código creado." ? "" : data.mensaje;
          form.hidden = true; res.hidden = false;
          if (window.amEvento) amEvento("envio_recomendacion");
        } else setStatus(form, data.mensaje || "No se ha podido crear tu enlace.", "error");
      } catch (err) {
        setStatus(form, "No hay conexión ahora mismo. Prueba de nuevo.", "error");
      } finally {
        btn.disabled = false; btn.textContent = "CREAR MI ENLACE";
      }
    });
    $("[data-ref-copy]", res).addEventListener("click", async () => {
      const input = $("[data-ref-link]", res);
      try { await navigator.clipboard.writeText(enlace); }
      catch (e) { input.select(); try { document.execCommand("copy"); } catch (x) {} }
      $("[data-ref-msg]", res).textContent = "Enlace copiado.";
    });
    $("[data-ref-share]", res).addEventListener("click", () => {
      const txt = "Te recomiendo Aiman Motors: gestionan el alquiler de coches de particulares. Si tienes un coche parado, mira cómo funciona: " + enlace;
      window.open("https://wa.me/?text=" + encodeURIComponent(txt), "_blank");
    });
  }

  const ref = refActual();
  initSimulador();
  initLlegadaReferido(ref);
  initPreevaluacion(ref);
  initDisponibilidad();
  initRecomendaciones();
})();
