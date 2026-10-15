@echo off
echo Compiling Tailwind CSS for Production...
tailwindcss.exe -i assets\css\input.css -o assets\css\tailwind.min.css --minify
echo Done! Output: assets\css\tailwind.min.css
