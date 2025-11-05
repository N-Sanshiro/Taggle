/**** Taggle – multi page flow (scan → result → cloth → history) ****/

const API_BASE = (localStorage.getItem('TAGGLE_API') || '');
const ANALYZE_ENDPOINT = '/api/scan_tag_dify';
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
  let obj = resp?.result ?? resp;
  if(typeof obj==='string'){ try{ obj = JSON.parse(obj);}catch{} }
  if((!obj || typeof obj!=='object') && resp?.raw){
    const outputs = resp.raw?.data?.outputs ?? {};
    obj = outputs.result_json ?? outputs.result ?? outputs.text ?? outputs.answer ?? obj;
    if(typeof obj==='string'){ try{ obj = JSON.parse(obj);}catch{} }
  }
  return (obj && typeof obj==='object') ? obj : {};
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
  try{
    const rawBlob = await snapshot(video, canvas);
    const blob = await resizeBlobToJpeg(rawBlob, 1280, .85);
    const tagImageDataURL = toDataURL(canvas);

    // 送信中の進捗％を表示
    const json = await postImage(
      joinUrl(API_BASE, ANALYZE_ENDPOINT),
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
      ts: Date.now(), api: json, tagImage: tagImageDataURL
    }));
    location.href = 'result.html';
    }catch(e){
      toastError('解析に失敗しました: ' + (e?.message || String(e)));
    }finally{
      setLoading(false);
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
  if(v.symbols.length){ v.symbols.forEach(s=>{ const b=document.createElement('span'); b.className='badge'; b.textContent=s; symEl.appendChild(b);}); }
  else symEl.textContent='-';

  document.getElementById('kvAdvice').textContent = v.advice;
  document.getElementById('kvConf').textContent   = v.confidence;
  document.getElementById('rawPre').textContent   = JSON.stringify(box.api, null, 2);

  const btnOk = document.getElementById('btnOk');
  if (btnOk) btnOk.onclick = ()=>{ location.href = 'cloth.html'; };
  const btnNo = document.getElementById('btnNo');
  if (btnNo) btnNo.onclick = ()=>{ location.href = 'index.html'; };
}

/* 3) cloth.html */
async function pageCloth(){
  const latest = JSON.parse(sessionStorage.getItem(SS_LATEST_KEY) || 'null');
  if(!latest){ location.replace('scan.html'); return; }

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
function pageHistory(){
  const list = JSON.parse(localStorage.getItem(LS_HISTORY_KEY) || '[]');
  const root = document.getElementById('historyList');
  if (!root) return;
  root.innerHTML = '';
  if(!list.length){
    root.innerHTML = '<p style="color:#083e41;opacity:.8">まだ保存がありません。</p>';
    return;
  }

  for(const item of list){
    const card = document.createElement('div');
    card.className = 'history-card';
    const ts = new Date(item.ts).toLocaleString();

    card.innerHTML = `
      <div class="thumb-row">
        <img src="${item.tagImage||''}" alt="タグ">
        <img src="${item.clothImage||''}" alt="外見">
      </div>
      <div class="meta">
        <div class="name">${item.result?.material || '-'}</div>
        <div class="temp">${item.result?.washTemp || '-'}</div>
        <div class="symbols" id="sym-${item.id}"></div>
        <div class="time" style="margin-top:4px;opacity:.7">${ts}</div>
      </div>
    `;
    root.appendChild(card);

    const sroot = card.querySelector(`#sym-${item.id}`);
    const syms = item.result?.symbols || [];
    if(syms.length){
      syms.forEach(s=>{
        const b = document.createElement('span');
        b.className = 'badge'; b.textContent = s; sroot.appendChild(b);
      });
    }
  }
}

/* ===== 天気（Open-Meteo） ===== */
const GEO = { lat: 33.92, lon: 134.65, tz: 'Asia/Tokyo' }; // 阿南市付近
const WMO = {
  0:'快晴',1:'晴れ',2:'晴れ時々くもり',3:'くもり',45:'霧',48:'霧',
  51:'霧雨(弱)',53:'霧雨',55:'霧雨(強)',61:'雨(弱)',63:'雨',65:'雨(強)',
  71:'雪(弱)',73:'雪',75:'大雪',80:'にわか雨(弱)',81:'にわか雨',82:'にわか雨(強)',
  95:'雷雨',96:'雷雨(雹)',99:'激しい雷雨'
};
const WMO_ICON = (c)=> c===0?'☀️':[1,2].includes(c)?'🌤️':c===3?'☁️':[51,53,55,61,63,65,80,81,82].includes(c)?'🌧️':[71,73,75].includes(c)?'🌨️':[95,96,99].includes(c)?'⛈️':[45,48].includes(c)?'🌫️':'⛅';
function buildAdviceToday(t,h,code){
  const rain = [61,63,65,80,81,82].includes(code);
  const good = t>=22 && h<=60 && !rain;
  const tips=[];
  if (good) tips.push('天気が良く、空気も乾燥。厚手の洗濯に最適！');
  if (h>=70) tips.push('湿度高め。部屋干しは除湿機・サーキュレーター併用。');
  if (rain) tips.push('雨の可能性あり。外干しは避け、部屋干し推奨。');
  if (t<=10) tips.push('気温が低めで乾きにくい。厚手は控えめに。');
  if (!tips.length) tips.push('通常どおりの洗濯でOK。ネット使用・裏返し推奨。');
  return tips.join(' ');
}
function buildAdviceTomorrow(maxT, rain){
  if (rain>=5) return '明日は降水が見込まれます。外干しは避け、洗濯は今日中に。';
  if (maxT>=25) return '明日はよく乾きそう。シーツやパーカーなど大物洗いに最適。';
  if (maxT<=12) return '明日は気温低め。厚手は乾きにくいので部屋干し器具を用意。';
  return '明日は通常どおりでOK。朝の天気で最終判断を。';
}
async function fetchWeather(){
  const url = `https://api.open-meteo.com/v1/forecast?latitude=${GEO.lat}&longitude=${GEO.lon}`
    + `&current=temperature_2m,relative_humidity_2m,weather_code`
    + `&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum`
    + `&timezone=${encodeURIComponent(GEO.tz)}`;
  const r = await fetch(url);
  if (!r.ok) throw new Error('天気の取得に失敗しました');
  return await r.json();
}

/* ---------- 起動 ---------- */
window.addEventListener('load', async ()=>{
  const page = document.body.dataset.page;
  try{
    if(page==='scan')   await pageScan();
    if(page==='result') pageResult();
    if(page==='cloth')  await pageCloth();
    if(page==='history')pageHistory();
  }catch(e){
    toastError(e?.message || String(e));
  }
});

/* ローディングUI */
/* ローディングUI（進捗対応） */
function ensureLoading(){
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
    el.addEventListener('click', (e) => e.stopPropagation(), { passive: true });
  }
  return el;
}

function setLoading(show, title = '処理中…', subtitle = 'しばらくお待ちください'){
  const el = ensureLoading();
  const root = document.documentElement;
  if (show) {
    el.querySelector('.title').textContent = title || '';
    el.querySelector('.subtitle').textContent = subtitle || '';
    // 初期化
    setLoadingProgress(0);
    el.style.display = 'flex';
    root.classList.add('no-scroll');
    document.body.setAttribute('aria-busy','true');
  } else {
    el.style.display = 'none';
    root.classList.remove('no-scroll');
    document.body.removeAttribute('aria-busy');
  }
}

/* 0〜100 の数値で進捗表示 */
function setLoadingProgress(pct){
  const el = ensureLoading();
  const bar = el.querySelector('.loading-meter .bar');
  const label = el.querySelector('.loading-percent');
  const v = Math.max(0, Math.min(100, Math.floor(pct || 0)));
  if (bar) bar.style.width = v + '%';
  if (label) label.textContent = v > 0 ? (v + '%') : '';
}

