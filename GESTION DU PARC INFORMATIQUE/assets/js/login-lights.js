/* ==========================================================================
   LOGIN-LIGHTS.JS - Lumières animées sur l'arrière-plan des écrans de connexion
   Un canvas est superposé à l'image de fond (.login-page) : des points
   lumineux scintillent et dérivent lentement, quelques halos flous passent
   en arrière-plan, et l'ensemble suit légèrement la souris (effet de profondeur).
   ========================================================================== */

(function(){
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  /* Sprite de lueur pré-rendu une seule fois (plus rapide qu'un dégradé par particule) */
  function makeGlowSprite(){
    const size = 64;
    const c = document.createElement("canvas");
    c.width = c.height = size;
    const g = c.getContext("2d");
    const grad = g.createRadialGradient(size/2, size/2, 0, size/2, size/2, size/2);
    grad.addColorStop(0,    "rgba(255,255,255,1)");
    grad.addColorStop(0.12, "rgba(220,242,255,0.9)");
    grad.addColorStop(0.35, "rgba(120,200,255,0.35)");
    grad.addColorStop(1,    "rgba(80,170,255,0)");
    g.fillStyle = grad;
    g.fillRect(0, 0, size, size);
    return c;
  }
  const glow = makeGlowSprite();

  function rand(min, max){ return min + Math.random() * (max - min); }

  /* Les lumières de l'image sont surtout à gauche : on y concentre les particules */
  function spawnX(w){ return Math.random() < 0.8 ? w * 0.6 * Math.pow(Math.random(), 1.3) : rand(0, w); }

  function createSpark(w, h, anywhere){
    return {
      x: spawnX(w),
      y: anywhere ? rand(0, h) : h + 10,
      r: rand(1.5, 5),                 // rayon de la lueur (px)
      vx: rand(-0.08, 0.18),           // dérive horizontale (px / frame à 60 fps)
      vy: rand(-0.35, -0.08),          // montée lente
      alpha: rand(0.35, 0.95),
      twinkle: rand(0.8, 2.4),         // vitesse de scintillement
      phase: rand(0, Math.PI * 2),
      depth: rand(0.2, 1)              // amplitude de l'effet souris
    };
  }

  function createOrb(w, h){
    return {
      x: spawnX(w), y: rand(0, h),
      r: rand(60, 160),
      vx: rand(-0.12, 0.12), vy: rand(-0.1, 0.1),
      alpha: rand(0.05, 0.13),
      phase: rand(0, Math.PI * 2),
      depth: rand(0.1, 0.4)
    };
  }

  function setupPage(page){
    const canvas = document.createElement("canvas");
    canvas.className = "login-lights";
    canvas.setAttribute("aria-hidden", "true");
    page.prepend(canvas);
    const ctx = canvas.getContext("2d");

    let w = 0, h = 0, sparks = [], orbs = [];
    const mouse = { x: 0, y: 0, tx: 0, ty: 0 };

    function resize(){
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = page.clientWidth; h = page.clientHeight;
      canvas.width = w * dpr; canvas.height = h * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const count = Math.min(90, Math.round(w * h / 16000));
      sparks = Array.from({ length: count }, () => createSpark(w, h, true));
      orbs = Array.from({ length: 6 }, () => createOrb(w, h));
    }

    page.addEventListener("pointermove", (e) => {
      mouse.tx = (e.clientX / w - 0.5) * 2;
      mouse.ty = (e.clientY / h - 0.5) * 2;
    });

    function draw(t, dt){
      ctx.clearRect(0, 0, w, h);
      ctx.globalCompositeOperation = "lighter";

      mouse.x += (mouse.tx - mouse.x) * 0.04;
      mouse.y += (mouse.ty - mouse.y) * 0.04;

      for (const o of orbs) {
        o.x += o.vx * dt; o.y += o.vy * dt;
        if (o.x < -o.r) o.x = w + o.r; else if (o.x > w + o.r) o.x = -o.r;
        if (o.y < -o.r) o.y = h + o.r; else if (o.y > h + o.r) o.y = -o.r;
        ctx.globalAlpha = o.alpha * (0.7 + 0.3 * Math.sin(t * 0.4 + o.phase));
        const d = o.r * 2;
        ctx.drawImage(glow, o.x - o.r - mouse.x * 30 * o.depth, o.y - o.r - mouse.y * 30 * o.depth, d, d);
      }

      for (let i = 0; i < sparks.length; i++) {
        const s = sparks[i];
        s.x += s.vx * dt; s.y += s.vy * dt;
        if (s.y < -20 || s.x > w + 20 || s.x < -20) { sparks[i] = createSpark(w, h, false); continue; }
        const tw = 0.5 + 0.5 * Math.sin(t * s.twinkle + s.phase);
        ctx.globalAlpha = s.alpha * (0.35 + 0.65 * tw);
        const d = s.r * 2 * (0.85 + 0.3 * tw);
        ctx.drawImage(glow, s.x - d/2 - mouse.x * 18 * s.depth, s.y - d/2 - mouse.y * 18 * s.depth, d, d);
      }

      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = "source-over";
    }

    resize();
    window.addEventListener("resize", resize);
    return { page, draw, resize, get size(){ return w + "x" + h; } };
  }

  function init(){
    const scenes = Array.from(document.querySelectorAll(".login-page")).map(setupPage);
    let last = performance.now();

    function loop(now){
      const dt = Math.min((now - last) / 16.67, 3); // normalisé sur 60 fps
      last = now;
      if (!document.hidden && !reduceMotion.matches) {
        for (const s of scenes) {
          if (s.page.hidden) continue;
          // La page vient d'apparaître (hidden retiré) : elle n'avait pas de taille
          if (s.size !== s.page.clientWidth + "x" + s.page.clientHeight) s.resize();
          s.draw(now / 1000, dt);
        }
      }
      requestAnimationFrame(loop);
    }
    requestAnimationFrame(loop);
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
