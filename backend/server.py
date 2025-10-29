import os, requests, json
from fastapi import FastAPI, File, UploadFile, Form
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

app = FastAPI()
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

DIFY_API_BASE = os.getenv("DIFY_API_BASE", "https://api.dify.ai").rstrip("/")
DIFY_API_KEY  = (os.getenv("DIFY_API_KEY") or "").strip()
DIFY_WORKFLOW_ID = os.getenv("DIFY_WORKFLOW_ID", "")
# 既定はあなたのWFと同じ「tag_image」に
DIFY_INPUT_VAR   = os.getenv("DIFY_INPUT_VAR", "tag_image")
DIFY_USER        = os.getenv("DIFY_USER", "taggle-app")

def _auth_headers(extra=None):
    h = {"Authorization": f"Bearer {DIFY_API_KEY}"}
    if extra: h.update(extra)
    return h

@app.post("/api/scan_tag_dify")
async def scan_tag_dify(file: UploadFile = File(...), name: str = Form("")):
    if not DIFY_API_KEY:
        return JSONResponse({"error": "DIFY_API_KEY not set"}, status_code=500)

    # --- 1) 画像アップロード ---
    try:
        upload_url = f"{DIFY_API_BASE}/v1/files/upload"
        files = {"file": (file.filename, await file.read(), file.content_type or "image/jpeg")}
        data  = {"user": DIFY_USER}
        up = requests.post(upload_url, headers=_auth_headers(), files=files, data=data, timeout=60)
    except Exception as e:
        return JSONResponse({"error": f"upload request failed: {e}"}, status_code=502)

    if up.status_code != 201:
        return JSONResponse({"error": "upload failed", "status": up.status_code, "text": up.text},
                            status_code=up.status_code)

    file_id = up.json().get("id")
    if not file_id:
        return JSONResponse({"error": "upload ok but no file id", "raw": up.text}, status_code=500)

    # --- 2) ワークフロー実行 ---
    try:
        run_url = f"{DIFY_API_BASE}/v1/workflows/run"   # ← 変数名を定義
        inputs = {
            DIFY_INPUT_VAR: {
                "type": "image",
                "transfer_method": "local_file",
                "upload_file_id": file_id,
            }
        }
        if name:
            inputs["name"] = name

        payload = {
            "inputs": inputs,
            "response_mode": "blocking",
            "user": DIFY_USER
        }
        if DIFY_WORKFLOW_ID:
            payload["workflow_id"] = DIFY_WORKFLOW_ID

        # ← data= ではなく json= を使う（Content-Typeも自動でOK）
        run = requests.post(run_url,
                            headers=_auth_headers({"Content-Type": "application/json"}),
                            json=payload,
                            timeout=180)
    except Exception as e:
        return JSONResponse({"error": f"run request failed: {e}"}, status_code=502)

    if run.status_code != 200:
        return JSONResponse({"error": "workflow run failed", "status": run.status_code, "text": run.text},
                            status_code=run.status_code)

    data = run.json()
    outputs = (data.get("data") or {}).get("outputs") or {}
    summary = outputs.get("text") or outputs.get("answer") or outputs or data

    return {"ok": True, "file_id": file_id, "outputs": outputs, "summary": summary, "raw": data}
