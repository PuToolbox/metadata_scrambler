const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const status = document.getElementById('status');

dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('dragover');
});
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

async function handleFiles(files) {
    status.innerHTML = 'Processing...';
    for (let file of files) {
        const formData = new FormData();
        formData.append('file', file);
        try {
            const response = await fetch('upload.php', {
                method: 'POST',
                body: formData
            });
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'scrambled_' + file.name;
                a.click();
                window.URL.revokeObjectURL(url);
                status.innerHTML += `<p>File processed: ${file.name}</p>`;
            } else {
                const error = await response.json();
                status.innerHTML += `<p class="error">Error for ${file.name}: ${error.error}</p>`;
            }
        } catch (error) {
            status.innerHTML += `<p class="error">Error for ${file.name}: ${error.message}</p>`;
        }
    }
}