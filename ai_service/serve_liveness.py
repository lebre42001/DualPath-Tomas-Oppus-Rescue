"""Local liveness worker; severity runtime and active checkpoint stay separate."""
import os
from fastapi import FastAPI
import uvicorn
from src.ai_service.liveness_api import router

app = FastAPI(title='AksyRes live face check')
app.include_router(router)
if __name__ == '__main__':
    uvicorn.run(app, host='127.0.0.1', port=int(os.getenv('FACE_LIVENESS_PORT', '8102')))
