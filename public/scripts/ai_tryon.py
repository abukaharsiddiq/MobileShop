import cv2
import numpy as np
import sys

def apply_real_look_tryon(customer_path, garment_path, output_path):
    try:
        customer = cv2.imread(customer_path)
        garment = cv2.imread(garment_path, cv2.IMREAD_UNCHANGED)

        if customer is None or garment is None: return

        h_c, w_c, _ = customer.shape
        face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
        faces = face_cascade.detectMultiScale(cv2.cvtColor(customer, cv2.COLOR_BGR2GRAY), 1.1, 5)

        if len(faces) > 0:
            (x, y, fw, fh) = faces[0]
            
            # জামার প্রস্থ ইউজারের ফেসের ৫.৮ গুণ করা হয়েছে যাতে তা কাঁধ ঢেকে দেয়
            new_w = int(fw * 5.8) 
            aspect_ratio = garment.shape[0] / garment.shape[1]
            new_h = int(new_w * aspect_ratio)
            garment_res = cv2.resize(garment, (new_w, new_h), interpolation=cv2.INTER_LANCZOS4)

            # পজিশন: জামার গলাকে আপনার থুতনির ঠিক নিচ থেকে শুরু করার জন্য লজিক
            off_x = x + (fw // 2) - (new_w // 2)
            # off_y এর মান আপনার ছবিতে জামা কত উপরে উঠবে তা ঠিক করে
            off_y = y + fh - int(fh * 0.4) 

            # আলফা ব্লেন্ডিং এবং গাউসিয়ান ব্লার (যাতে জামার ধারগুলো চামড়ার সাথে মিশে যায়)
            for c in range(0, 3):
                alpha = garment_res[:, :, 3] / 255.0
                alpha = cv2.GaussianBlur(alpha, (7, 7), 0) # বেশি স্মুথ ফিনিশিং এর জন্য
                
                y1, y2 = max(0, off_y), min(h_c, off_y + new_h)
                x1, x2 = max(0, off_x), min(w_c, off_x + new_w)
                
                region = customer[y1:y2, x1:x2, c]
                g_part = garment_res[0:y2-y1, 0:x2-x1, c]
                a_part = alpha[0:y2-y1, 0:x2-x1]

                customer[y1:y2, x1:x2, c] = (a_part * g_part + (1 - a_part) * region)

        cv2.imwrite(output_path, customer)
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    apply_real_look_tryon(sys.argv[1], sys.argv[2], sys.argv[3])