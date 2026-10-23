import os
import requests
import time
import sys
import json

# আপনার API টোকেন
API_TOKEN = os.environ.get("REPLICATE_SCRIPT_API_TOKEN", "")

def run_tryon_direct(human_url, garment_url):
    headers = {
        "Authorization": f"Token {API_TOKEN}",
        "Content-Type": "application/json"
    }

    # ১. প্রেডিকশন শুরু করা
    payload = {
        "version": "c871bb9b0ad830786889623f198a6e40f2341501174517ca9c24090b8523ea24",
        "input": {
            "human_img": human_url,
            "garm_img": garment_url,
            "garment_des": "a traditional outfit",
            "category": "upper_body"
        }
    }

    try:
        response = requests.post("https://api.replicate.com/v1/predictions", json=payload, headers=headers)
        prediction = response.json()
        
        if "id" not in prediction:
            return f"Error: {prediction.get('detail', 'Unknown error')}"

        predict_id = prediction["id"]
        status_url = f"https://api.replicate.com/v1/predictions/{predict_id}"

        # ২. রেজাল্ট তৈরি হওয়া পর্যন্ত চেক করা (Polling)
        while True:
            res = requests.get(status_url, headers=headers).json()
            if res["status"] == "succeeded":
                return res["output"] # এটি ইমেজের ইউআরএল দিবে
            elif res["status"] == "failed":
                return "Error: Prediction failed"
            
            time.sleep(2) # ২ সেকেন্ড পর পর চেক করবে

    except Exception as e:
        return f"Request Error: {str(e)}"

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Error: Missing arguments")
    else:
        result = run_tryon_direct(sys.argv[1], sys.argv[2])
        print(result)