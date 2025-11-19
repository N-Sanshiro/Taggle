/**** Taggle – multi page flow (scan → result → cloth → history) ****/

// app.js 冒頭
const API_BASE = '/Taggle';                      // ここを固定
const ANALYZE_ENDPOINT    = '/Taggle/backend/scan_tag_dify.py';
const SAVE_IMAGE_ENDPOINT = 'api/save_image.php';
const LS_HISTORY_KEY = 'TAGGLE_HISTORY';
const SS_LATEST_KEY  = 'TAGGLE_LATEST';   // scan→result 渡し用

function joinUrl(base, path) {
  const b = (base || '').replace(/\/+$/,''); const p = (path || '').replace(/^\/+/, '');
  return b ? (b + '/' + p) : ('/' + p);
}

/* ---------- 共通小物 ---------- */
function ensureModal() {
  const modal = document.querySelector('.modal'); if (!modal) return null;
  const close = modal.querySelector('.close'); if (close) close.addEventListener('click', () => modal.style.display='none');
  modal.addEventListener('click', (e)=>{ if(e.target===modal) modal.style.display='none';});
  return modal;
}
function toastError(msg){
  const modal = ensureModal(); if (!modal) { alert(msg); return; }
  modal.querySelector('pre')?.textContent !== undefined
    ? (modal.querySelector('pre').textContent = msg)
    : alert(msg);
  if (modal) modal.style.display='flex';
}
function toDataURL(canvas){ try { return canvas.toDataURL('image/jpeg', 0.9); } catch { return ''; } }

/* 画像縮小 */
async function resizeBlobToJpeg(blob, maxEdge = 1280, quality = 0.85) {
  const img = new Image(); const url = URL.createObjectURL(blob);
  try{
    await new Promise((res,rej)=>{ img.onload = res; img.onerror=()=>rej(new Error('image load failed')); img.src=url; });
    const w0 = img.naturalWidth||img.width, h0 = img.naturalHeight||img.height;
    const ratio = Math.min(1, maxEdge/Math.max(w0,h0));
    const w = Math.round(w0*ratio), h = Math.round(h0*ratio);
    const cvs = document.createElement('canvas'); cvs.width=w; cvs.height=h;
    cvs.getContext('2d').drawImage(img,0,0,w,h);
    return await new Promise((res,rej)=>cvs.toBlob(b=>b?res(b):rej(new Error('resize toBlob 失敗')),'image/jpeg',quality));
  } finally { URL.revokeObjectURL(url); }
}
function snapshot(videoEl, canvasEl) {
  const w = videoEl.videoWidth||1280, h = videoEl.videoHeight||720;
  canvasEl.width=w; canvasEl.height=h;
  canvasEl.getContext('2d').drawImage(videoEl,0,0,w,h);
  return new Promise((res,rej)=>canvasEl.toBlob(b=>b?res(b):rej(new Error('toBlob 失敗')),'image/jpeg',0.9));
}
async function useCamera(videoEl) {
  const stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:{ideal:'environment'}}, audio:false });
  videoEl.srcObject = stream; videoEl.setAttribute('playsinline',''); videoEl.muted = true;
  await new Promise((res)=>{ const ready=()=>res(); if(videoEl.readyState>=1&&videoEl.videoWidth>0) return ready();
    videoEl.addEventListener('loadedmetadata',ready,{once:true}); videoEl.addEventListener('canplay',ready,{once:true});});
  try{ await videoEl.play(); }catch{ const onTap=()=>{ videoEl.play().finally(()=>document.removeEventListener('touchend',onTap));}; document.addEventListener('touchend',onTap,{once:true}); }
  return ()=>stream.getTracks().forEach(t=>t.stop());
}
async function postImage(url, blob, extraForm = {}, { signal, onProgress } = {}) {
  return new Promise((resolve, reject) => {
    const fd = new FormData();
    fd.append('file', blob, 'photo.jpg');
    for (const k in extraForm) if (Object.prototype.hasOwnProperty.call(extraForm, k)) {
      fd.append(k, extraForm[k]);
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);

    xhr.onreadystatechange = () => {
      if (xhr.readyState === 4) {
        const ok = xhr.status >= 200 && xhr.status < 300;
        let data = null;
        try { data = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch {}
        if (ok) {
          resolve(data ?? { raw: xhr.responseText });
        } else {
          let detail = (data && typeof data==='object' && data.detail!=null) ? data.detail : (data ?? xhr.statusText);
          if (typeof detail !== 'string') { try { detail = JSON.stringify(detail,null,2);} catch { detail = String(detail);} }
          reject(new Error(detail));
        }
      }
    };

    // アップロード進捗（送信中の%）
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && typeof onProgress === 'function') {
        onProgress((e.loaded / e.total) * 100);
      }
    };

    if (signal) {
      const onAbort = () => { try { xhr.abort(); } catch {} reject(new DOMException('Aborted','AbortError')); };
      if (signal.aborted) return onAbort();
      signal.addEventListener('abort', onAbort, { once: true });
    }

    xhr.send(fd);
  });
}


/* 結果の抽出＆成形（Dify出力に柔軟に対応） */
function extractResultObject(resp){
  // どの形式でも柔軟に拾う
  let obj = resp?.result || resp;

  // result が null のとき raw.outputs も見る
  if (!obj || typeof obj !== 'object') {
    obj = resp?.raw?.data?.outputs?.result_json 
       || resp?.raw?.data?.outputs?.result 
       || resp?.raw?.data?.outputs?.text 
       || obj;
  }

  // JSON文字列→パース
  if (typeof obj === 'string') {
    try{ obj = JSON.parse(obj); }catch{}
  }

  return (obj && typeof obj === 'object') ? obj : {};
}

function shapeResult(obj){
  const material = obj.material || obj.素材 || obj.materials || '-';
  const washTemp = (obj.wash_temp ?? obj.washTemp ?? obj.temperature ?? null);
  const symbols  = obj.symbols || obj.warnings || obj.marks || [];
  const advice   = obj.advice || obj.summary || obj.comment || '-';
  const conf     = (obj.confidence != null) ? obj.confidence : (obj.score != null ? obj.score : null);
  return {
    material: String(material || '-'),
    washTemp: (typeof washTemp === 'number') ? `${washTemp}℃` : (washTemp || '-'),
    symbols: Array.isArray(symbols) ? symbols : (symbols ? [String(symbols)] : []),
    advice: String(advice || '-'),
    confidence: (conf != null && !isNaN(Number(conf))) ? `${Math.round(Number(conf)*100)}%` : '-',
    rawObj: obj
  };
}

// ===== セッションから id_user を取得する共通関数 =====
async function getUserIdFromSession() {
  try {
    const r = await fetch(joinUrl(API_BASE, 'api/check_session.php'), {
      credentials: 'same-origin',
    });
    const js = await r.json();
    if (js && js.id_user != null) {
      return js.id_user;
    }
  } catch (e) {
    console.warn('getUserIdFromSession failed:', e);
  }
  return null;
}

/* ---------- ページ別ロジック ---------- */

/* 1) scan.html */
async function pageScan(){
  const video = document.getElementById('tagCam');
  const canvas= document.getElementById('tagCanvas');
  const btn   = document.getElementById('tagSnap');
  if (!video || !canvas || !btn) return;

  const stop = await useCamera(video);

  btn.onclick = async ()=>{
  btn.disabled = true; const prev = btn.textContent; btn.textContent = '解析中…';
  setLoading(true, 'タグを解析中', 'AIに送信しています');
  const ac = new AbortController();
  let navigating = false;
  try{
    const rawBlob = await snapshot(video, canvas);
    const blob = await resizeBlobToJpeg(rawBlob, 1280, .85);
    const tagImageDataURL = toDataURL(canvas);

    // 送信中の進捗％を表示
    const json = await postImage(
      ANALYZE_ENDPOINT,
      blob,
      { name:'' },
      {
        signal: ac.signal,
        onProgress: (pct) => setLoadingProgress(pct)
      }
    );

    // サーバから返ってきた後は「処理中」に文言変更（任意）
    setLoading(true, '結果を処理中', '少々お待ちください');

    sessionStorage.setItem(SS_LATEST_KEY, JSON.stringify({
      ts: Date.now(),
      api: json,
      tagImage: tagImageDataURL,
      tagImageId: (json && (json.image_id || json.result?.image_id)) || null
    }));
    navigating = true;
    location.href = 'result.html';
    }catch(e){
      toastError('解析に失敗しました: ' + (e?.message || String(e)));
    }finally{
      if (!navigating) setLoading(false);
      btn.textContent = prev; btn.disabled = false; stop();
    }
  };
}

/* 2) result.html */
function pageResult(){
  const box = JSON.parse(sessionStorage.getItem(SS_LATEST_KEY) || 'null');
  if(!box){ location.replace('scan.html'); return; }

  const obj = extractResultObject(box.api);
  const v   = shapeResult(obj);

  document.getElementById('tagThumb').src = box.tagImage || '';
  document.getElementById('kvMaterial').textContent = v.material;
  document.getElementById('kvTemp').textContent = v.washTemp;

  const symEl = document.getElementById('kvSymbols'); symEl.innerHTML='';
  if (v.symbols && v.symbols.length) {
    // 「、」で区切って整形
    const text = v.symbols
      .map(s => s.trim())
      .filter(Boolean)
      .join('\n'); // ← 句読点＋改行で区切る

    // 複数行で見やすく表示
    const pre = document.createElement('pre');
    pre.style.whiteSpace = 'pre-wrap';
    pre.style.lineHeight = '1.6';
    pre.style.margin = '0';
    pre.textContent = text;

    symEl.appendChild(pre);
  } else {
    symEl.textContent = '-';
  }
  document.getElementById('kvAdvice').textContent = v.advice;
  document.getElementById('kvConf').textContent   = v.confidence;
  document.getElementById('rawPre').textContent   = JSON.stringify(box.api, null, 2);

  // ↓↓↓ ここから差し替え：ログイン状態でボタン表示と遷移を切り替え
  const btnOk = document.getElementById('btnOk');
  const btnNo = document.getElementById('btnNo');

  // 既定（ログイン済み時）の挙動を定義
  function setBehaviorLoggedIn(){
    if (btnOk) {
      btnOk.textContent = 'OK（保存へ）';
      btnOk.onclick = ()=>{ location.href = 'cloth.html'; };
    }
    if (btnNo) {
      btnNo.textContent = 'NO（ホームに戻る）';
      btnNo.onclick = ()=>{ location.href = 'index.html'; };
    }
  }

  // 未ログイン時の挙動を定義（ログイン画面へ誘導）
  function setBehaviorLoggedOut(){
    if (btnOk) {
      btnOk.textContent = 'OK（ログインして保存）';
      btnOk.onclick = ()=>{ location.href = joinUrl(API_BASE, './api/mypage.php') + '?next=' + encodeURIComponent('cloth.html'); };
    }
    if (btnNo) {
      btnNo.textContent = 'NO（ホームに戻る）';
      btnNo.onclick = ()=>{ location.href = joinUrl(API_BASE, '/frontend/index.html') + '?next=' + encodeURIComponent('index.html'); };
    }
  }

  // セッション確認 → 状態で切り替え
  (async ()=>{
    try{
      const r = await fetch(joinUrl(API_BASE, 'api/check_session.php'), { credentials: 'same-origin' });
      const js = await r.json();
      if (js && (js.id_user != null || js.logged_in === true)) {
        setBehaviorLoggedIn();
      } else {
        setBehaviorLoggedOut();
      }
    }catch{
      // 取得失敗時は安全側（未ログイン扱い）
      setBehaviorLoggedOut();
    }
  })();
  // ↑↑↑ ここまで差し替え
}

/* 3) cloth.html */
async function pageCloth(){
  const latest = JSON.parse(sessionStorage.getItem(SS_LATEST_KEY) || 'null');
  if(!latest){ location.replace('result.html'); return; }

  const video = document.getElementById('clothCam');
  const canvas= document.getElementById('clothCanvas');
  const btn   = document.getElementById('clothSnap');
  if (!video || !canvas || !btn) return;

  const stop = await useCamera(video);

  btn.onclick = async ()=>{
    btn.disabled = true; const prev=btn.textContent; btn.textContent='保存中…';
    setLoading(true, '外見を保存中', '結果と写真をセットにしています');
    try{
      await snapshot(video, canvas);
      const clothImage = toDataURL(canvas);

      try {
       const clothBlob = await new Promise((res) => {
        canvas.toBlob((b)=>res(b), 'image/jpeg', 0.9);
       });
       const latestObj = JSON.parse(sessionStorage.getItem(SS_LATEST_KEY) || 'null');
       const tagImageId = latestObj?.tagImageId || null;
       await postImage(
        joinUrl(API_BASE, SAVE_IMAGE_ENDPOINT),
        clothBlob,
        tagImageId ? { tag_image_id: String(tagImageId) } : {}
       );

       try {
          const userId = await getUserIdFromSession();   // ← セッションから id_user を取得
          if (userId != null) {
            const fd = new FormData();
            fd.append('file', clothBlob, 'cloth.jpg');
            fd.append('user_id', String(userId));
            fd.append('name', '');   // 服の名前は不要なので空文字（null扱い）

            await fetch("/Taggle/backend/api/register_cloth_vec", {
              method: 'POST',
              body: fd
            });
          } else {
            console.warn('userId が取得できないため vec 登録をスキップ');
          }
        } catch (e) {
          console.warn('vec register failed:', e);
        }
      } catch (e) {
        console.warn('cloth save failed:', e);
      }

      const history = JSON.parse(localStorage.getItem(LS_HISTORY_KEY) || '[]');
      const obj = extractResultObject(latest.api);
      const shaped = shapeResult(obj);

      history.unshift({
        id: crypto.randomUUID ? crypto.randomUUID() : String(Date.now()),
        ts: Date.now(),
        tagImage: latest.tagImage || '',
        clothImage,
        result: shaped,
        raw: latest.api
      });
      localStorage.setItem(LS_HISTORY_KEY, JSON.stringify(history));

      sessionStorage.removeItem(SS_LATEST_KEY);
      location.href = 'history.html';
    }catch(e){
      toastError('保存に失敗しました: ' + (e?.message || String(e)));
    }finally{
      setLoading(false);
      btn.textContent = prev; btn.disabled=false; stop();
    }
  };
}

/* 4) history.html */
async function pageHistory(){
  const root = document.getElementById('historyList');
  if (!root) return;
  root.innerHTML = '<p>読み込み中...</p>';

  try {
    const r = await fetch('/Taggle/api/get_history.php', { credentials: 'same-origin' });
    const tx = await r.text();

    let js;
    try { js = JSON.parse(tx); }
    catch { root.innerHTML = `<pre style="color:red;white-space:pre-wrap">${tx}</pre>`; return; }

    if (!js.ok) { root.innerHTML = `<p style="color:red">${js.error||'取得失敗'}</p>`; return; }

    const list = js.rows || [];
    if (!list.length) { root.innerHTML = '<p style="opacity:.8">まだ保存がありません。</p>'; return; }

    root.innerHTML = '';

    // 時刻フォーマッタ（tags.created_at を ISO/UNIX どちらでも受け付け）
    const formatTime = (v) => {
      try {
        const d = (typeof v === 'number')
          ? new Date(v * (v < 1e12 ? 1000 : 1))      // 秒/ミリ秒両対応
          : new Date(String(v));                     // ISO 文字列など
        if (isNaN(d.getTime())) return '—';
        return new Intl.DateTimeFormat('ja-JP', {
          year:'numeric', month:'2-digit', day:'2-digit',
          hour:'2-digit', minute:'2-digit'
        }).format(d).replace(/\//g,'/');
      } catch { return '—'; }
    };

    for (const row of list) {
      // ★ result.html と同じ整形
      const obj = extractResultObject({ result: row.result });
      const v   = shapeResult(obj);

      // created_at（無ければ row.ts などフォールバック）
      const createdRaw = row.created_at ?? row.ts ?? null;
      const createdStr = formatTime(createdRaw);

      const card = document.createElement('div');
      card.className = 'history-card';
      if (row.id_cloth != null) {
        card.dataset.clothId = String(row.id_cloth);   // 類似検索で使う
      }
      card.innerHTML = `
        <div class="thumb-row">
          <img src="${row.tag_image || ''}" alt="タグ">
          <img src="${row.cloth_image || ''}" alt="外見">
        </div>

        <time class="time">${createdStr}</time>

        <details class="desc">
          <summary>タグの説明を表示</summary>
          <div class="meta">
            <div class="name">${v.material}</div>
            <div class="temp">${v.washTemp}</div>
            <div class="symbols"></div>
            <div class="advice">${v.advice}</div>
            <div class="confidence">信頼度: ${v.confidence}</div>
          </div>
        </details>
      `;

      // シンボルバッジ
      const sroot = card.querySelector('.symbols');
      if (v.symbols && v.symbols.length) {
        v.symbols.forEach(s => {
          const b = document.createElement('span');
          b.className = 'badge';
          b.textContent = s;
          sroot.appendChild(b);
        });
      } else {
        sroot.textContent = '-';
      }

      root.appendChild(card);
    }
  } catch (e) {
    root.innerHTML = `<p style="color:red">読み込み失敗: ${e.message}</p>`;
  }
}

// ======== ResNet 埋め込みを使ったカメラ類似検索 ========
// ======== ResNet 埋め込みを使ったカメラ類似検索 ========
async function setupHistoryFinderVec() {
  const panel   = document.getElementById('finderPanel');
  const cam     = document.getElementById('finderCam');
  const canvas  = document.getElementById('finderCanvas');
  const btnOn   = document.getElementById('finderStart');
  const btnSnap = document.getElementById('finderSnap');
  const info    = document.getElementById('finderResult');
  const root    = document.getElementById('historyList');

  const noMatchOverlay = ensureNoMatchOverlay();

  if (!panel || !cam || !canvas || !btnOn || !btnSnap || !info || !root) return;

  let stopCam = null;

  // カメラ停止処理
  const stopCamera = () => {
    if (stopCam) {
      try { stopCam(); } catch {}
      stopCam = null;
    }
    if (cam.srcObject) {
      try { cam.srcObject.getTracks().forEach(t => t.stop()); } catch {}
      cam.srcObject = null;
    }
  };

  // カメラ起動処理
  const startCamera = async () => {
    try {
      setLoading(true, 'カメラ起動', '準備中…');
      stopCamera();
      stopCam = await useCamera(cam);
      btnSnap.disabled = false;
      info.textContent = '探したい服をフレームいっぱいに映して撮影してください。';
      if (!panel.open) panel.open = true;
    } catch (e) {
      toastError(e?.message || String(e));
    } finally {
      setLoading(false);
    }
  };

  // --- 「似ている服は見つかりませんでした」オーバーレイの閉じる ---
  const closeBtn =
    noMatchOverlay.querySelector('.no-match-close') ||
    noMatchOverlay.querySelector('#noMatchClose');

  const onOverlayClose = (e) => {
    if (e) e.stopPropagation();
    noMatchOverlay.style.display = 'none';

    // いったんカメラを止めてパネルも閉じる
    stopCamera();
    if (panel.open) panel.open = false;
    btnSnap.disabled = true;
    info.textContent = '';

    // ページまるごと再読み込み（カメラも履歴一覧も初期状態に戻る）
    location.reload();
  };

  if (closeBtn) {
    closeBtn.addEventListener('click', onOverlayClose);
  }
  noMatchOverlay.addEventListener('click', (e) => {
    if (e.target === noMatchOverlay) {
      onOverlayClose(e);
    }
  });

  // 初期状態
  btnSnap.disabled = true;

  // パネル開閉
  panel.addEventListener('toggle', async () => {
    if (panel.open) {
      await startCamera();
    } else {
      stopCamera();
      btnSnap.disabled = true;
      info.textContent = '';
    }
  });

  // 「カメラ起動」ボタン
  btnOn.onclick = async () => {
    await startCamera();
  };

  // 撮影 → /api/match_cloth_vec
  btnSnap.onclick = async () => {
    // カメラが止まっていたら起動
    if (!cam.srcObject) {
      await startCamera();
      if (!cam.srcObject) return;
    }

    try {
      setLoading(true, '検索中', '似ている服を探しています');

      // 撮影してJPEGにする
      await snapshot(cam, canvas);
      const blob = await new Promise(res =>
        canvas.toBlob(b => res(b), 'image/jpeg', 0.9)
      );

      const userId = await getUserIdFromSession();
      if (userId == null) {
        throw new Error('ログイン情報が取得できませんでした');
      }

      const fd = new FormData();
      fd.append('file', blob, 'query.jpg');
      fd.append('user_id', String(userId));
      fd.append('threshold', '0.8');
      fd.append('top_k', '10');

      // ★ 類似検索API（FastAPI側）にPOST
      const r = await fetch('/Taggle/backend/api/match_cloth_vec', {
        method: 'POST',
        body: fd,
      });
      const js = await r.json();

      if (!js.ok) {
        throw new Error(js.error || '検索に失敗しました');
      }

      const matches = js.matches || [];

      // 類似服なし → オーバーレイを出す（閉じるとリロード）
      if (!matches.length) {
        info.textContent = '似ている服は見つかりませんでした。';
        noMatchOverlay.style.display = 'flex';
        return;
      }

      // 見つかったとき（元の並べ替え処理）
      info.textContent =
        `見つかりました（上位 ${Math.min(matches.length, 5)} 件を先頭に表示）：`;

      const cards = Array.from(root.querySelectorAll('.history-card'));
      const byId  = new Map(cards.map(c => [c.dataset.clothId, c]));
      root.innerHTML = '';

      for (const m of matches) {
        const id = String(m.id_cloth);
        const card = byId.get(id);
        if (card) {
          const meta = card.querySelector('.meta') || card;
          const oldSim = meta.querySelector('.similarity');
          if (oldSim) oldSim.remove();
          meta.insertAdjacentHTML(
            'beforeend',
            `<div class="similarity" style="margin-top:4px;font-size:.85rem;color:#17656a;">
              類似度: ${(m.score * 100).toFixed(1)}%
            </div>`
          );
          root.appendChild(card);
          byId.delete(id);
        }
      }

      // その他のカードを後ろに追加
      for (const [, card] of byId) {
        root.appendChild(card);
      }

    } catch (e) {
      toastError('検索に失敗: ' + (e?.message || String(e)));
    } finally {
      setLoading(false);
    }
  };
}




window.addEventListener('load', async () => {
  const page = document.body.dataset.page;   // <body data-page="result"> の値
  try {
    if (page === 'scan')    { await pageScan(); }
    if (page === 'result')  { pageResult(); }
    if (page === 'cloth')   { await pageCloth(); }
    if (page === 'history') { pageHistory(); await setupHistoryFinderVec(); }
  } catch (e) {
    console.error('[boot] init error:', e);
    toastError(e?.message || String(e));
  }
});

/* ===== 天気（Open-Meteo） ===== */

// 位置（阿南市付近・日本時間）
const GEO = { lat: 33.92, lon: 134.65, tz: 'Asia/Tokyo' };

// WMOコード（天気コード→日本語表記）
const WMO = { 0:'快晴',1:'晴れ',2:'晴れ時々くもり',3:'くもり',45:'霧',48:'霧',
  51:'霧雨(弱)',53:'霧雨',55:'霧雨(強)',61:'雨(弱)',63:'雨',65:'雨(強)',
  71:'雪(弱)',73:'雪',75:'大雪',80:'にわか雨(弱)',81:'にわか雨',82:'にわか雨(強)',
  95:'雷雨',96:'雷雨(雹)',99:'激しい雷雨' };
const WMO_ICON = (c)=> c===0?'☀️':[1,2].includes(c)?'🌤️':c===3?'☁️'
  :[51,53,55,61,63,65,80,81,82].includes(c)?'🌧️'
  :[71,73,75].includes(c)?'🌨️':[95,96,99].includes(c)?'⛈️'
  :[45,48].includes(c)?'🌫️':'⛅';

// Open-Meteo APIから拡張データを取得
async function fetchWeather() {
  const url =
    `https://api.open-meteo.com/v1/forecast?latitude=${GEO.lat}&longitude=${GEO.lon}`
    + `&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m`
    + `&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,`
    + `precipitation_probability_max,uv_index_max,wind_speed_10m_max`
    + `&timezone=${encodeURIComponent(GEO.tz)}`;

  const r = await fetch(url);
  if (!r.ok) throw new Error('天気の取得に失敗しました');
  return r.json();
}

/* --- 洗濯向けアドバイス生成 --- */

// 簡易 乾きやすさ指標（0〜100）
function dryingIndex({ t, h, wind, rainProb }) {
  let s = 50;
  s += (t - 15) * 2;          // 気温
  s += (60 - h) * 0.7;        // 湿度
  s += (wind - 2) * 4;        // 風
  s -= (rainProb || 0) * 0.6; // 降水確率
  return Math.max(0, Math.min(100, Math.round(s)));
}

// 今日のアドバイス
function buildAdviceToday(t, h, code){
  const willRain = [61,63,65,80,81,82].includes(code);
  const idx = dryingIndex({ t, h });
  const tips = [];

  if (idx >= 75)      tips.push('よく乾く日：シーツや厚手も狙い目。');
  else if (idx >= 55) tips.push('まずまず乾く：午前中に干すと◎。');
  else if (idx >= 35) tips.push('乾きにくい：薄手中心＋送風/除湿を併用。');
  else                tips.push('部屋干し推奨：除湿機とサーキュレーター必須。');

  if (h >= 70) tips.push('湿度高め：除湿機＋サーキュレーター併用。');
  if (t <= 10) tips.push('気温低め：厚手は平干し/二段干しで風を当てる。');
  if (willRain) tips.push('降水の可能性：外干しは避ける。');
  tips.push('デリケートはネット使用・弱コース。');
  tips.push('重い衣類はハンガー2本 or 平干しで型崩れ防止。');
  return tips;
}

// 明日のアドバイス
function buildAdviceTomorrow(maxT, rainSum){
  const idx = dryingIndex({ t: maxT, h: 60 });
  const tips = [];

  if (idx >= 75)      tips.push('明日はよく乾きそう：大物洗いに最適。');
  else if (idx >= 55) tips.push('明日はまずまず：朝干し推奨。');
  else if (idx >= 35) tips.push('明日は乾きにくい見込み：室内補助を準備。');
  else                tips.push('明日は部屋干しが無難。');

  if (rainSum >= 3) tips.push('降水見込み：外干しは避ける。');
  tips.push('夜のうちに洗って朝一で干すと乾きやすい。');
  return tips;
}

/* --- 表示 --- */
function renderAdvice(el, linesOrText){
  let lines = [];
  if (Array.isArray(linesOrText)) {
    lines = linesOrText.slice();
  } else {
    lines = String(linesOrText || '')
      .split(/\r?\n|[,、。]\s*/)
      .map(s => s.trim())
      .filter(Boolean);
  }

  // 先頭の「・」「-」「*」などを削除（二重防止）
  const items = lines.map(t =>
    t.replace(/^[\s　]*[・･•●○\-\*]+[\s　]*/, '').trim()
  );

  if (!items.length) {
    el.innerHTML = '—';
    return;
  }

  const html = items.map(t =>
    `<li><span class="dot" aria-hidden="true">・</span><span class="txt">${t}</span></li>`
  ).join('');

  el.innerHTML = `<ul class="advice-list">${html}</ul>`;
}

/*
window.addEventListener('load', async () => {
  try {
    const iconToday = document.getElementById('wxIconToday');
    const iconTomorrow = document.getElementById('wxIconTomorrow');
    if (!iconToday || !iconTomorrow) {
      return;
    }

    // 今日
    const t  = Math.round(data.current.temperature_2m);
    const h  = data.current.relative_humidity_2m;
    const wc = data.current.weather_code;
    document.getElementById('wxIconToday').textContent = WMO_ICON(wc);
    document.getElementById('wxDescToday').textContent = (WMO && WMO[wc]) ? WMO[wc] : '—';
    document.getElementById('wxTempToday').textContent = `${t}℃`;
    document.getElementById('wxHumToday').textContent  = `${h}%`;
    renderAdvice(document.getElementById('wxAdviceToday'), buildAdviceToday(t, h, wc));


    // 明日
    const i = 1;
    const wc2  = data.daily.weather_code[i];
    const tmax = Math.round(data.daily.temperature_2m_max[i]);
    const tmin = Math.round(data.daily.temperature_2m_min[i]);
    const rain = data.daily.precipitation_sum[i];
    document.getElementById('wxIconTomorrow').textContent = WMO_ICON(wc2);
    document.getElementById('wxDescTomorrow').textContent = (WMO && WMO[wc2]) ? WMO[wc2] : '—';
    document.getElementById('wxTempTomorrow').textContent = `${tmax}℃ / ${tmin}℃`;
    document.getElementById('wxRainTomorrow').textContent = `${rain} mm`;
    renderAdvice(document.getElementById('wxAdviceTomorrow'), buildAdviceTomorrow(tmax, rain));

  } catch (e) {
    console.error('天気の取得エラー:', e);
    const el = document.getElementById('wxAdviceToday');
    if (el) el.textContent = '天気情報の取得に失敗しました。';
  }
});
*/

/* --- ページロード時に呼び出す処理 --- */
function ensureLoading() {
  let el = document.getElementById('loading');
  if (!el) {
    el = document.createElement('div');
    el.id = 'loading';
    el.className = 'loading-overlay';
    el.innerHTML = `
      <div class="loading-panel" role="status" aria-live="polite">
        <div class="spinner" aria-hidden="true"></div>
        <div class="loading-text">
          <div class="title"></div>
          <div class="subtitle"></div>
          <div class="loading-meter" aria-hidden="true"><div class="bar"></div></div>
          <div class="loading-percent" aria-hidden="true"></div>
        </div>
      </div>`;
    document.body.appendChild(el);
    // クリックで消えないように伝播防止
    el.addEventListener('click', (e) => e.stopPropagation(), { passive: true });
  }
  return el;
}

// 「似ている服がありません」用オーバーレイ
function ensureNoMatchOverlay() {
  let el = document.getElementById('noMatchOverlay');
  if (!el) {
    el = document.createElement('div');
    el.id = 'noMatchOverlay';
    el.className = 'no-match-overlay';
    el.innerHTML = `
      <div class="no-match-panel">
        <p class="no-match-text">似ている服は見つかりませんでした。</p>
        <button type="button" class="btn-pill ok-btn no-match-close">閉じる</button>
      </div>
    `;
    document.body.appendChild(el);
  }
  return el;
}



function setLoading(show, title = '処理中…', subtitle = 'しばらくお待ちください') {
  const el = ensureLoading();
  const root = document.documentElement;

  if (show) {
    el.querySelector('.title').textContent = title || '';
    el.querySelector('.subtitle').textContent = subtitle || '';

    // 初期化
    setLoadingProgress(0);
    el.style.display = 'flex';
    root.classList.add('no-scroll');
    document.body.setAttribute('aria-busy', 'true');
  } else {
    el.style.display = 'none';
    root.classList.remove('no-scroll');
    document.body.removeAttribute('aria-busy');
  }
}

/* 0〜100 の数値で進捗表示 */
function setLoadingProgress(pct) {
  const el = ensureLoading();
  const bar = el.querySelector('.loading-meter .bar');
  const label = el.querySelector('.loading-percent');
  const v = Math.max(0, Math.min(100, Math.floor(pct || 0)));

  if (bar) bar.style.width = v + '%';
  if (label) label.textContent = v > 0 ? (v + '%') : '';
}