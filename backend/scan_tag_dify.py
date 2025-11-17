#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os, json, cgi, cgitb, requests
cgitb.enable()

# .env 読み込み（任意）
try:
    from dotenv import load_dotenv, find_dotenv
    load_dotenv(find_dotenv())
except Exception:
    pass

def getenv(k, default=""):
    v = os.getenv(k, default)
    return v.strip() if isinstance(v, str) else v

DIFY_API_BASE    = getenv("DIFY_API_BASE", "https://api.dify.ai").rstrip("/")
DIFY_API_KEY     = getenv("DIFY_API_KEY")              # app- でも可（あなたの環境）
DIFY_WORKFLOW_ID = getenv("DIFY_WORKFLOW_ID", "")
DIFY_INPUT_VAR   = getenv("DIFY_INPUT_VAR", "tag_image")
DIFY_USER        = getenv("DIFY_USER", "taggle-app")

HEADERS_JSON = {"Authorization": f"Bearer {DIFY_API_KEY}", "Content-Type": "application/json"}
HEADERS_AUTH = {"Authorization": f"Bearer {DIFY_API_KEY}"}

def upload_file_to_dify(filename: str, content: bytes) -> str:
    """ /v1/files/upload へアップロードし file_id を返す """
    url = f"{DIFY_API_BASE}/v1/files/upload"
    files = {"file": (filename or "photo.jpg", content, "image/jpeg")}
    data  = {"user": DIFY_USER}
    r = requests.post(url, headers=HEADERS_AUTH, files=files, data=data, timeout=60)
    if r.status_code != 201:
        raise RuntimeError(f"upload failed: {r.status_code} {r.text}")
    j = r.json()
    return j.get("id")

def run_workflow_with_file(file_id: str, item_name: str):
    """ /v1/workflows/run を実行 """
    url = f"{DIFY_API_BASE}/v1/workflows/run"
    inputs = {
        DIFY_INPUT_VAR: {
            "type": "image",
            "transfer_method": "local_file",
            "upload_file_id": file_id,
        },
        "item_name": item_name,
    }
    payload = {
        "inputs": inputs,
        "response_mode": "blocking",
        "user": DIFY_USER,
    }
    if DIFY_WORKFLOW_ID:
        payload["workflow_id"] = DIFY_WORKFLOW_ID

    r = requests.post(url, headers=HEADERS_JSON, json=payload, timeout=180)
    if r.status_code != 200:
        raise RuntimeError(f"workflow run failed: {r.status_code} {r.text}")
    return r.json()

def main():
    print("Content-Type: application/json; charset=utf-8")
    print("Access-Control-Allow-Origin: *")
    print()

    if not DIFY_API_KEY:
        print(json.dumps({"ok": False, "error": "DIFY_API_KEY is missing"}))
        return

    form = cgi.FieldStorage()
    fileitem = form["file"] if "file" in form else None
    name = form.getfirst("name", "")

    if not fileitem or not getattr(fileitem, "file", None):
        print(json.dumps({"ok": False, "error": "no file"}))
        return

    try:
        content = fileitem.file.read()
        file_id = upload_file_to_dify(fileitem.filename, content)
        data = run_workflow_with_file(file_id, name)

        outputs = (data.get("data") or {}).get("outputs") or {}
        result = outputs.get("result_json") or outputs.get("result") or outputs
        print(json.dumps({"ok": True, "file_id": file_id, "result": result, "raw": data}, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=False))

if __name__ == "__main__":
    main()
