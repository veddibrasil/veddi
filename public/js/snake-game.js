(function () {
    'use strict';

    var COLS = 15, ROWS = 10, CELL = 18;
    var tickMs = 150;
    var state = 'idle'; // idle | running | paused | over
    var score = 0, best = 0;
    var snake = [], dir = {x:1,y:0}, nextDir = {x:1,y:0};
    var food = {x:0,y:0};
    var tickTimer = null;
    var canvas = null, ctx = null;
    var swipeStart = null;
    var visible = true;
    var keyHandler = null;

    // ── DOM references ────────────────────────────────────────────────────────
    var els = {};

    function $(id) { return document.getElementById(id); }

    function render() {
        if (!els.score) return;
        els.score.textContent = score;
        els.best.textContent  = best;

        var labels = { idle:'Aguardando', running:'Jogando', paused:'Pausado', over:'Game Over' };
        els.stateLabel.textContent = labels[state] || '';
        els.stateLabel.className = 'mc-sg-badge ' + state;

        // overlay
        var showOverlay = state !== 'running' && state !== 'paused';
        els.overlay.style.display = showOverlay ? 'flex' : 'none';
        els.overlayIdle.style.display = state === 'idle' ? '' : 'none';
        els.overlayOver.style.display = state === 'over'  ? '' : 'none';
        els.overlayScore.textContent = score;

        // update pause button icon
        var pauseBtn = $('mc-sg-pause-btn');
        if (pauseBtn) pauseBtn.textContent = state === 'paused' ? '\u25B6' : '\u23F8';
    }

    // ── Game loop ─────────────────────────────────────────────────────────────
    function startTick() {
        stopTick();
        tickTimer = setInterval(tick, tickMs);
    }

    function stopTick() {
        if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
    }

    function tick() {
        if (state !== 'running') return;
        if (!canvas || !canvas.isConnected) { stopTick(); return; }
        dir = Object.assign({}, nextDir);
        var head = {
            x: (snake[0].x + dir.x + COLS) % COLS,
            y: (snake[0].y + dir.y + ROWS) % ROWS,
        };
        if (snake.some(function(s){ return s.x === head.x && s.y === head.y; })) {
            gameOver(); return;
        }
        snake.unshift(head);
        if (head.x === food.x && head.y === food.y) {
            score++;
            spawnFood();
            var ms = Math.max(80, 150 - Math.floor(score / 5) * 10);
            if (ms !== tickMs) { tickMs = ms; startTick(); }
        } else {
            snake.pop();
        }
        draw();
        render();
    }

    function gameOver() {
        stopTick();
        state = 'over';
        if (score > best) {
            best = score;
            try { localStorage.setItem('mc_snake_best', best); } catch(e){}
        }
        draw();
        render();
    }

    function startGame() {
        var mx = Math.floor(COLS / 2), my = Math.floor(ROWS / 2);
        snake    = [{x:mx,y:my},{x:mx-1,y:my},{x:mx-2,y:my}];
        dir      = {x:1,y:0};
        nextDir  = {x:1,y:0};
        score    = 0;
        tickMs   = 150;
        spawnFood();
        state = 'running';
        startTick();
        render();
    }

    function spawnFood() {
        var pos;
        do {
            pos = { x: Math.floor(Math.random()*COLS), y: Math.floor(Math.random()*ROWS) };
        } while (snake.some(function(s){ return s.x === pos.x && s.y === pos.y; }));
        food = pos;
    }

    function tryDir(d) {
        if (state !== 'running') return;
        if (d.x !== -dir.x || d.y !== -dir.y) nextDir = d;
    }

    // ── Canvas drawing ────────────────────────────────────────────────────────
    function draw() {
        if (!ctx) return;
        var W = COLS * CELL, H = ROWS * CELL;
        ctx.fillStyle = '#FAFAF8';
        ctx.fillRect(0, 0, W, H);

        // grid
        ctx.strokeStyle = '#F0EFED';
        ctx.lineWidth = 0.5;
        for (var x = 0; x <= COLS; x++) {
            ctx.beginPath(); ctx.moveTo(x*CELL,0); ctx.lineTo(x*CELL,H); ctx.stroke();
        }
        for (var y = 0; y <= ROWS; y++) {
            ctx.beginPath(); ctx.moveTo(0,y*CELL); ctx.lineTo(W,y*CELL); ctx.stroke();
        }

        // food
        ctx.font = (CELL-2) + 'px serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('\uD83E\uDD5F', food.x*CELL+CELL/2, food.y*CELL+CELL/2);

        // snake body
        snake.forEach(function(seg, i) {
            ctx.fillStyle = i === 0 ? '#da291c' : (i % 2 === 0 ? '#e8392b' : '#c0261a');
            var rx = seg.x*CELL+1, ry = seg.y*CELL+1, rw = CELL-2, rh = CELL-2, r = 4;
            ctx.beginPath();
            if (ctx.roundRect) {
                ctx.roundRect(rx, ry, rw, rh, r);
            } else {
                ctx.moveTo(rx+r, ry);
                ctx.arcTo(rx+rw,ry,   rx+rw,ry+rh, r);
                ctx.arcTo(rx+rw,ry+rh,rx,   ry+rh, r);
                ctx.arcTo(rx,   ry+rh,rx,   ry,    r);
                ctx.arcTo(rx,   ry,   rx+rw,ry,    r);
                ctx.closePath();
            }
            ctx.fill();
        });

        // eyes on head
        if (snake.length > 0) {
            var h = snake[0];
            var ex = h.x*CELL+CELL/2, ey = h.y*CELL+CELL/2;
            var perp = {x: -dir.y, y: dir.x};
            [1, -1].forEach(function(side) {
                var px = ex + perp.x*3*side + dir.x*3;
                var py = ey + perp.y*3*side + dir.y*3;
                ctx.fillStyle = 'white';
                ctx.beginPath(); ctx.arc(px, py, 2.5, 0, Math.PI*2); ctx.fill();
                ctx.fillStyle = '#222';
                ctx.beginPath(); ctx.arc(px, py, 1, 0, Math.PI*2); ctx.fill();
            });
        }
    }

    function drawIdle() {
        if (!ctx) return;
        var W = COLS*CELL, H = ROWS*CELL;
        ctx.fillStyle = '#FAFAF8';
        ctx.fillRect(0, 0, W, H);
        ctx.font = '22px serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        [[50,45],[170,110],[285,55],[110,175],[300,160],[200,195],[75,180]].forEach(function(p) {
            ctx.globalAlpha = 0.15;
            ctx.fillText('\uD83E\uDD5F', p[0], p[1]);
        });
        ctx.globalAlpha = 1;
    }

    // ── Input ─────────────────────────────────────────────────────────────────
    var KEY_MAP = {
        ArrowUp:    {x:0,y:-1}, ArrowDown:  {x:0,y:1},
        ArrowLeft:  {x:-1,y:0}, ArrowRight: {x:1,y:0},
    };

    function onKey(e) {
        if (!visible) return;
        if (KEY_MAP[e.key]) {
            e.preventDefault();
            tryDir(KEY_MAP[e.key]);
            return;
        }
        if (e.code === 'Space') {
            e.preventDefault();
            if (state === 'running') { state = 'paused'; stopTick(); draw(); render(); }
            else if (state === 'paused') { state = 'running'; startTick(); render(); }
        }
    }

    // ── Build UI ──────────────────────────────────────────────────────────────
    function buildUI(container) {
        container.innerHTML = [
            '<style>',
            '.mc-sg-badge{font-size:9px;font-weight:700;padding:2px 8px;border-radius:9999px;letter-spacing:.3px}',
            '.mc-sg-badge.idle{background:rgba(255,255,255,.1);color:rgba(255,255,255,.4)}',
            '.mc-sg-badge.running{background:rgba(34,197,94,.2);color:#4ade80}',
            '.mc-sg-badge.paused{background:rgba(251,191,36,.2);color:#fbbf24}',
            '.mc-sg-badge.over{background:rgba(239,68,68,.25);color:#f87171}',
            '.mc-sg-canvas-wrap{position:relative;border-radius:10px;overflow:hidden;',
            'box-shadow:0 4px 20px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.06)}',
            '.mc-sg-overlay{position:absolute;inset:0;display:flex;flex-direction:column;',
            'align-items:center;justify-content:center;gap:10px;',
            'background:rgba(250,250,248,.93);backdrop-filter:blur(4px)}',
            '.mc-sg-btn{padding:8px 24px;border-radius:10px;font-size:13px;font-weight:800;',
            'color:#fff;background:linear-gradient(135deg,#ef4444,#b91c1c);border:none;cursor:pointer;',
            'box-shadow:0 3px 10px rgba(220,38,38,.4);transition:all .15s}',
            '.mc-sg-btn:hover{transform:translateY(-1px)}',
            '.mc-sg-btn:active{transform:scale(.97)}',
            '.mc-sg-dpad{display:grid;grid-template-columns:repeat(3,34px);grid-template-rows:repeat(3,34px);gap:3px}',
            '.mc-sg-dpad button{width:34px;height:34px;border:none;cursor:pointer;border-radius:8px;',
            'font-size:14px;display:flex;align-items:center;justify-content:center;',
            'background:rgba(255,255,255,.07);color:rgba(255,255,255,.4);',
            'border:1px solid rgba(255,255,255,.08);',
            'transition:all .1s;-webkit-user-select:none;user-select:none}',
            '.mc-sg-dpad button:hover{background:rgba(255,255,255,.13);color:rgba(255,255,255,.8)}',
            '.mc-sg-dpad button:active{background:rgba(220,38,38,.35);color:#fff;transform:scale(.88)}',
            '.mc-sg-dpad-pause{background:rgba(220,38,38,.15)!important;color:rgba(239,68,68,.7)!important;font-size:11px!important}',
            '.mc-sg-dpad-pause:active{background:rgba(220,38,38,.4)!important}',
            '</style>',
            '<div id="mc-sg-panel">',
            '  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">',
            '    <div style="display:flex;align-items:center;gap:10px">',
            '      <div>',
            '        <div style="font-size:7px;font-weight:600;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.5px">Pontos</div>',
            '        <div style="font-size:17px;font-weight:900;color:#fff;line-height:1;font-variant-numeric:tabular-nums"><span id="mc-sg-score">0</span></div>',
            '      </div>',
            '      <div style="width:1px;height:20px;background:rgba(255,255,255,.1)"></div>',
            '      <div>',
            '        <div style="font-size:7px;font-weight:600;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.5px">Recorde</div>',
            '        <div style="font-size:17px;font-weight:900;color:rgba(255,255,255,.3);line-height:1;font-variant-numeric:tabular-nums"><span id="mc-sg-best">0</span></div>',
            '      </div>',
            '    </div>',
            '    <span id="mc-sg-state" class="mc-sg-badge idle">Aguardando</span>',
            '  </div>',
            '  <div class="mc-sg-canvas-wrap">',
            '    <canvas id="mc-sg-canvas" width="270" height="180" style="width:100%;display:block;touch-action:none;user-select:none"></canvas>',
            '    <div class="mc-sg-overlay" id="mc-sg-overlay">',
            '      <div id="mc-sg-idle" style="text-align:center">',
            '        <div style="font-size:26px">\uD83E\uDD5F</div>',
            '        <div style="font-size:13px;font-weight:800;color:#1f2937;margin-top:4px">Coxinha Dash</div>',
            '        <div style="font-size:10px;color:#9ca3af;margin-top:2px">Colete as coxinhas sem bater!</div>',
            '      </div>',
            '      <div id="mc-sg-over" style="text-align:center;display:none">',
            '        <div style="font-size:24px">\uD83D\uDCA5</div>',
            '        <div style="font-size:13px;font-weight:800;color:#dc2626;margin-top:4px">Game Over!</div>',
            '        <div style="font-size:10px;color:#6b7280;margin-top:2px">Pontos: <strong id="mc-sg-over-score">0</strong></div>',
            '      </div>',
            '      <button class="mc-sg-btn" id="mc-sg-play-btn">Jogar \u2192</button>',
            '    </div>',
            '  </div>',
            '  <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-top:8px">',
            '    <div class="mc-sg-dpad">',
            '      <div></div><button data-dir="up">\u25B2</button><div></div>',
            '      <button data-dir="left">\u25C4</button>',
            '      <button class="mc-sg-dpad-pause" id="mc-sg-pause-btn">\u23F8</button>',
            '      <button data-dir="right">\u25BA</button>',
            '      <div></div><button data-dir="down">\u25BC</button><div></div>',
            '    </div>',
            '    <p style="font-size:9px;color:rgba(255,255,255,.18);line-height:1.6;text-align:left">',
            '      \u2190\u2192\u2191\u2193 mover<br>\u23F8 pausar',
            '    </p>',
            '  </div>',
            '</div>',
        ].join('');

        // cache refs
        els.score      = $('mc-sg-score');
        els.best       = $('mc-sg-best');
        els.stateLabel = $('mc-sg-state');
        els.overlay    = $('mc-sg-overlay');
        els.overlayIdle  = $('mc-sg-idle');
        els.overlayOver  = $('mc-sg-over');
        els.overlayScore = $('mc-sg-over-score');
        els.panel      = $('mc-sg-panel');
        els.toggleOpen  = null;
        els.toggleClose = null;

        canvas = $('mc-sg-canvas');
        ctx    = canvas.getContext('2d');

        // events
        $('mc-sg-play-btn').addEventListener('click', startGame);

        $('mc-sg-pause-btn').addEventListener('click', function() {
            if (state === 'running') { state = 'paused'; stopTick(); draw(); render(); }
            else if (state === 'paused') { state = 'running'; startTick(); render(); }
        });

        var DPAD = { up:{x:0,y:-1}, down:{x:0,y:1}, left:{x:-1,y:0}, right:{x:1,y:0} };
        container.querySelectorAll('[data-dir]').forEach(function(btn) {
            btn.addEventListener('click', function() { tryDir(DPAD[btn.dataset.dir]); });
        });

        // keyboard (attach once, guard by flag)
        if (keyHandler) document.removeEventListener('keydown', keyHandler);
        keyHandler = onKey;
        document.addEventListener('keydown', keyHandler);

        // touch swipe
        canvas.addEventListener('touchstart', function(e) {
            swipeStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }, { passive: true });
        canvas.addEventListener('touchend', function(e) {
            if (!swipeStart || state !== 'running') return;
            var dx = e.changedTouches[0].clientX - swipeStart.x;
            var dy = e.changedTouches[0].clientY - swipeStart.y;
            if (Math.abs(dx) > Math.abs(dy)) tryDir(dx > 0 ? {x:1,y:0} : {x:-1,y:0});
            else tryDir(dy > 0 ? {x:0,y:1} : {x:0,y:-1});
            swipeStart = null;
        }, { passive: true });

        // initial state
        try { best = parseInt(localStorage.getItem('mc_snake_best') || '0'); } catch(e) {}
        render();
        drawIdle();
    }

    // ── Auto-init: detecta quando o elemento aparece no DOM ──────────────────
    function tryAutoInit() {
        var el = document.getElementById('mc-snake-game');
        if (el && el.children.length === 0) {
            stopTick();
            visible = true;
            state   = 'idle';
            score   = 0;
            snake   = [];
            buildUI(el);
        }
    }

    function onOpen() {
        if (!document.getElementById('mc-snake-game')) return;
        var el = document.getElementById('mc-snake-game');
        if (el.children.length === 0) { tryAutoInit(); return; }
        if (state === 'paused') { state = 'running'; startTick(); render(); }
    }

    function onClose() {
        if (state === 'running') { state = 'paused'; stopTick(); render(); }
    }

    // Roda após cada atualização do Livewire (transição de step)
    document.addEventListener('livewire:updated', tryAutoInit);
    // Roda imediatamente (script está no fim do body, DOM já está pronto)
    tryAutoInit();

    // ── Public API (chamada manual, caso necessário) ───────────────────────────
    window.SnakeDash = {
        init: function(container) {
            if (!container) return;
            stopTick();
            visible = true;
            state   = 'idle';
            score   = 0;
            snake   = [];
            buildUI(container);
        },
        onOpen:  onOpen,
        onClose: onClose,
    };

}());
