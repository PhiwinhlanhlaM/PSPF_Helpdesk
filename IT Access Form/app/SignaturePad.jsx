// Signature pad - supports drawing (mouse/touch) and image upload.
// Returns { kind: "drawn" | "uploaded", strokes?: [...], dataUrl?: "..." } via onChange.

const { useRef, useState, useEffect, useCallback } = React;

// ---- Saved-signature localStorage helpers ----
const SIG_STORAGE_KEY = 'pspf_saved_signature';

function loadSavedSignature() {
  try {
    const raw = localStorage.getItem(SIG_STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch { return null; }
}

function persistSignature(sig) {
  try { localStorage.setItem(SIG_STORAGE_KEY, JSON.stringify(sig)); } catch {}
}

function clearSavedSignature() {
  try { localStorage.removeItem(SIG_STORAGE_KEY); } catch {}
}

// ---- SavePrompt modal ----
function SavePrompt({ onSave, onSkip }) {
  return (
    <div style={{
      position: "fixed", inset: 0, background: "rgba(0,0,0,0.4)",
      zIndex: 9999, display: "flex", alignItems: "center", justifyContent: "center",
    }}>
      <div className="card card-pad slide-up" style={{ width: 360, maxWidth: "95vw" }}>
        <h2 className="card-title" style={{ marginBottom: 6 }}>Save signature?</h2>
        <p className="card-subtitle" style={{ marginBottom: 16 }}>
          Save your signature locally so you can reuse it next time without drawing again.
          It is stored only in this browser.
        </p>
        <div className="row gap-2" style={{ justifyContent: "flex-end" }}>
          <button className="btn btn-secondary" onClick={onSkip}>Skip</button>
          <button className="btn btn-primary" onClick={onSave}>
            <Icon name="check" size={14}/> Save signature
          </button>
        </div>
      </div>
    </div>
  );
}

// ---- SavedSignaturePanel ----
// Shown instead of the full pad when a saved signature exists.
function SavedSignaturePanel({ saved, onUse, onDrawNew }) {
  return (
    <div className="sigpad">
      <div style={{
        padding: "12px 14px", background: "var(--green-50,#f0fdf4)",
        border: "1.5px solid var(--green-300,#86efac)", borderRadius: 8, marginBottom: 10,
      }}>
        <div className="row gap-2" style={{ alignItems: "center", marginBottom: 6 }}>
          <Icon name="check-circle" size={14} style={{ color: "var(--green-700)" }}/>
          <strong style={{ fontSize: 12.5, color: "var(--green-800,#166534)" }}>Saved signature</strong>
        </div>
        <div style={{ background: "white", borderRadius: 6, padding: 8, border: "1px solid var(--ink-100)", display: "inline-block" }}>
          <SignatureRender signature={saved} width={200} height={56}/>
        </div>
      </div>
      <div className="row gap-2">
        <button type="button" className="btn btn-primary btn-sm" onClick={onUse}>
          <Icon name="check" size={13}/> Use saved signature
        </button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={onDrawNew}>
          Draw new
        </button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => { clearSavedSignature(); onDrawNew(); }}
          style={{ color: "var(--red-600)" }}>
          <Icon name="trash" size={12}/> Remove saved
        </button>
      </div>
    </div>
  );
}

function SignaturePad({ onChange, height = 200 }) {
  const canvasRef = useRef(null);
  const containerRef = useRef(null);
  const [strokes, setStrokes] = useState([]);
  const [drawing, setDrawing] = useState(false);
  const [uploaded, setUploaded] = useState(null);
  const [mode, setMode] = useState("draw"); // draw | upload
  const currentStrokeRef = useRef(null);

  // Saved-signature state
  const [savedSig, setSavedSig] = useState(() => loadSavedSignature());
  const [showSaved, setShowSaved] = useState(() => !!loadSavedSignature());
  const [showSavePrompt, setShowSavePrompt] = useState(false);
  const pendingSigRef = useRef(null); // sig to potentially save after prompt

  // Auto-apply saved signature to parent on first render so the form isn't blocked
  useEffect(() => {
    const saved = loadSavedSignature();
    if (saved) onChange && onChange(saved);
    // eslint-disable-next-line
  }, []);

  const resize = useCallback(() => {
    const c = canvasRef.current;
    const wrap = containerRef.current;
    if (!c || !wrap) return;
    const cssWidth = wrap.clientWidth;
    if (!cssWidth) return;
    const dpr = window.devicePixelRatio || 1;
    c.width = Math.floor(cssWidth * dpr);
    c.height = Math.floor(height * dpr);
    c.style.width = cssWidth + "px";
    c.style.height = height + "px";
    const ctx = c.getContext("2d");
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.scale(dpr, dpr);
    redraw(strokes);
  }, [strokes, height]);

  useEffect(() => {
    resize();
    const ro = new ResizeObserver(resize);
    if (containerRef.current) ro.observe(containerRef.current);
    return () => ro.disconnect();
    // eslint-disable-next-line
  }, []);

  function redraw(allStrokes) {
    const c = canvasRef.current; if (!c) return;
    const ctx = c.getContext("2d");
    const rect = c.getBoundingClientRect();
    ctx.clearRect(0, 0, rect.width, rect.height);
    ctx.strokeStyle = "#0e1726";
    ctx.lineWidth = 2.2;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    for (const stroke of allStrokes) {
      if (stroke.length < 2) continue;
      ctx.beginPath();
      ctx.moveTo(stroke[0][0], stroke[0][1]);
      for (let i = 1; i < stroke.length; i++) ctx.lineTo(stroke[i][0], stroke[i][1]);
      ctx.stroke();
    }
  }

  function getPoint(e) {
    const c = canvasRef.current;
    const rect = c.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return [t.clientX - rect.left, t.clientY - rect.top];
  }

  function handleStart(e) {
    e.preventDefault();
    setDrawing(true);
    currentStrokeRef.current = [getPoint(e)];
  }
  function handleMove(e) {
    if (!drawing) return;
    e.preventDefault();
    const p = getPoint(e);
    currentStrokeRef.current.push(p);
    const c = canvasRef.current; const ctx = c.getContext("2d");
    const stroke = currentStrokeRef.current;
    if (stroke.length >= 2) {
      const [a, b] = [stroke[stroke.length - 2], stroke[stroke.length - 1]];
      ctx.strokeStyle = "#0e1726"; ctx.lineWidth = 2.2; ctx.lineCap = "round"; ctx.lineJoin = "round";
      ctx.beginPath(); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]); ctx.stroke();
    }
  }
  function handleEnd() {
    if (!drawing) return;
    setDrawing(false);
    const stroke = currentStrokeRef.current;
    if (stroke && stroke.length >= 2) {
      const next = [...strokes, stroke];
      setStrokes(next);
      const sig = { kind: "drawn", strokes: normalize(next) };
      pendingSigRef.current = sig;
      pushChange(sig);
      // After first complete stroke, ask about saving (only if no saved sig yet)
      if (!savedSig && next.length === 1) {
        setShowSavePrompt(true);
      }
    }
    currentStrokeRef.current = null;
  }

  function normalize(allStrokes) {
    const c = canvasRef.current;
    const rect = c.getBoundingClientRect();
    return allStrokes.map(s => s.map(([x, y]) => [+(x / rect.width).toFixed(4), +(y / rect.height).toFixed(4)]));
  }

  function pushChange(payload) {
    onChange && onChange(payload);
  }

  function clear() {
    setStrokes([]);
    setUploaded(null);
    redraw([]);
    pushChange(null);
  }
  function undo() {
    if (strokes.length === 0) return;
    const next = strokes.slice(0, -1);
    setStrokes(next);
    redraw(next);
    pushChange(next.length ? { kind: "drawn", strokes: normalize(next) } : null);
  }

  function handleUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
      const dataUrl = ev.target.result;
      setUploaded(dataUrl);
      setMode("upload");
      const sig = { kind: "uploaded", dataUrl };
      pendingSigRef.current = sig;
      pushChange(sig);
      if (!savedSig) setShowSavePrompt(true);
    };
    reader.readAsDataURL(file);
  }

  function handleSaveYes() {
    if (pendingSigRef.current) {
      persistSignature(pendingSigRef.current);
      setSavedSig(pendingSigRef.current);
    }
    setShowSavePrompt(false);
  }

  function handleSaveSkip() {
    setShowSavePrompt(false);
  }

  // User clicks "Use saved signature"
  function useSaved() {
    pushChange(savedSig);
    setShowSaved(false); // collapse back to pad (already applied)
    // Keep showSaved false so user can draw new if they want
  }

  function drawNew() {
    setShowSaved(false);
    pushChange(null); // clear current value until they sign
  }

  const isEmpty = mode === "draw" ? strokes.length === 0 : !uploaded;

  // If saved sig exists and we haven't dismissed it, show the saved panel
  if (showSaved && savedSig) {
    return (
      <>
        {showSavePrompt && <SavePrompt onSave={handleSaveYes} onSkip={handleSaveSkip}/>}
        <SavedSignaturePanel
          saved={savedSig}
          onUse={useSaved}
          onDrawNew={drawNew}
        />
      </>
    );
  }

  return (
    <>
      {showSavePrompt && <SavePrompt onSave={handleSaveYes} onSkip={handleSaveSkip}/>}
      <div className="sigpad">
        <div className="sigpad-tabs">
          <button
            type="button"
            className={"sigpad-tab " + (mode === "draw" ? "active" : "")}
            onClick={() => { setMode("draw"); pushChange(strokes.length ? { kind: "drawn", strokes: normalize(strokes) } : null); }}
          >
            <Icon name="pen" size={14}/> Draw
          </button>
          <button
            type="button"
            className={"sigpad-tab " + (mode === "upload" ? "active" : "")}
            onClick={() => { setMode("upload"); pushChange(uploaded ? { kind: "uploaded", dataUrl: uploaded } : null); }}
          >
            <Icon name="upload" size={14}/> Upload image
          </button>
          {savedSig && (
            <button
              type="button"
              className="sigpad-tab"
              onClick={() => { setShowSaved(true); }}
              style={{ marginLeft: "auto", color: "var(--green-700)", fontSize: 12 }}
            >
              <Icon name="check-circle" size={13}/> Use saved
            </button>
          )}
        </div>

        {mode === "draw" && (
          <div className="sigpad-canvas-wrap" ref={containerRef}>
            <canvas
              ref={canvasRef}
              className="sigpad-canvas"
              onMouseDown={handleStart}
              onMouseMove={handleMove}
              onMouseUp={handleEnd}
              onMouseLeave={handleEnd}
              onTouchStart={handleStart}
              onTouchMove={handleMove}
              onTouchEnd={handleEnd}
            />
            {isEmpty && (
              <div className="sigpad-placeholder">
                <Icon name="pen" size={18}/>
                <span>Draw your signature here</span>
              </div>
            )}
            <div className="sigpad-baseline"></div>
            <div className="sigpad-x">×</div>
          </div>
        )}

        {mode === "upload" && (
          <div className="sigpad-upload-wrap">
            {uploaded ? (
              <div className="sigpad-upload-preview">
                <img src={uploaded} alt="Signature"/>
              </div>
            ) : (
              <label className="sigpad-upload-drop">
                <Icon name="upload" size={20}/>
                <div className="row gap-1" style={{ flexDirection: "column", alignItems: "center" }}>
                  <strong>Click to upload signature image</strong>
                  <span className="muted" style={{ fontSize: 12 }}>PNG, JPG, or SVG · transparent background recommended</span>
                </div>
                <input type="file" accept="image/png,image/jpeg,image/svg+xml" onChange={handleUpload} style={{ display: "none" }}/>
              </label>
            )}
          </div>
        )}

        <div className="sigpad-actions">
          <div className="muted" style={{ fontSize: 12 }}>
            {mode === "draw"
              ? (isEmpty ? "Use mouse, trackpad, or touch" : `${strokes.length} stroke${strokes.length === 1 ? "" : "s"}`)
              : (isEmpty ? "Image will replace the drawn signature" : "Image attached")}
          </div>
          <div className="row gap-2">
            {mode === "draw" && (
              <>
                <button type="button" className="btn btn-ghost btn-sm" onClick={undo} disabled={isEmpty}>
                  <Icon name="undo" size={13}/> Undo
                </button>
                <button type="button" className="btn btn-ghost btn-sm" onClick={clear} disabled={isEmpty}>
                  <Icon name="trash" size={13}/> Clear
                </button>
              </>
            )}
            {mode === "upload" && uploaded && (
              <button type="button" className="btn btn-ghost btn-sm" onClick={clear}>
                <Icon name="trash" size={13}/> Remove
              </button>
            )}
          </div>
        </div>
      </div>
    </>
  );
}

// ---------- Read-only signature renderer (for thumbnails + timelines) ----------
function SignatureRender({ signature, width = 120, height = 40, color = "#0e1726", strokeWidth = 1.4 }) {
  const canvasRef = useRef(null);

  useEffect(() => {
    const c = canvasRef.current; if (!c) return;
    const dpr = window.devicePixelRatio || 1;
    c.width = width * dpr; c.height = height * dpr;
    c.style.width = width + "px"; c.style.height = height + "px";
    const ctx = c.getContext("2d");
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, width, height);

    if (!signature) return;

    if (signature.kind === "uploaded" && signature.dataUrl) {
      const img = new Image();
      img.onload = () => {
        const ratio = Math.min(width / img.width, height / img.height);
        const w = img.width * ratio, h = img.height * ratio;
        ctx.drawImage(img, (width - w) / 2, (height - h) / 2, w, h);
      };
      img.src = signature.dataUrl;
      return;
    }

    if (signature.kind === "drawn" && signature.strokes) {
      ctx.strokeStyle = color;
      ctx.lineWidth = strokeWidth;
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
      for (const stroke of signature.strokes) {
        if (stroke.length < 2) continue;
        ctx.beginPath();
        ctx.moveTo(stroke[0][0] * width, stroke[0][1] * height);
        for (let i = 1; i < stroke.length; i++) {
          ctx.lineTo(stroke[i][0] * width, stroke[i][1] * height);
        }
        ctx.stroke();
      }
    }
  }, [signature, width, height, color, strokeWidth]);

  return <canvas ref={canvasRef} className="sigrender"/>;
}

window.SignaturePad = SignaturePad;
window.SignatureRender = SignatureRender;
