import sys
import asyncio

if sys.platform == 'win32':
    asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())

import os

os.environ['ONNXRUNTIME_PROVIDER'] = 'CPUExecutionProvider'
from rembg import remove
from PIL import Image

def remove_background(input_path, output_path):
    try:
        if not os.path.exists(input_path):
            return

        with open(input_path, 'rb') as i:
            input_data = i.read()
            output_data = remove(input_data)
            with open(output_path, 'wb') as o:
                o.write(output_data)
        
        sys.stdout.write("SUCCESS")
        sys.stdout.flush() 
    except Exception as e:
        sys.stderr.write(str(e))
        sys.stderr.flush()
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) > 2:
        remove_background(sys.argv[1], sys.argv[2])