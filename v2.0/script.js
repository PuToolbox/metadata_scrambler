const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const status = document.getElementById('status');
const metadataContainer = document.getElementById('metadata-container');

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
    status.textContent = 'Processing...';
    metadataContainer.innerHTML = ''; // Clear previous content

    if (files.length === 0) {
        status.textContent = 'No files selected.';
        return;
    }

    for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        try {
            const response = await fetch('upload.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (response.ok && result.success) {
                status.innerHTML += `<p>File processed: ${result.filename}</p>`;
                displayMetadata(result.scrambledMetadata, result.filename, result.token);
            } else {
                status.innerHTML += `<p class="error">Error processing file: ${result.error}</p>`;
            }
        } catch (error) {
            status.innerHTML += `<p class="error">Error processing file: ${error.message}</p>`;
        }
    }
}

function displayMetadata(scrambled, filename, token) {
    const fileSection = document.createElement('div');
    fileSection.className = 'file-section';

    const title = document.createElement('h3');
    title.textContent = `File: ${filename}`;
    fileSection.appendChild(title);

    const table = document.createElement('table');
    table.className = 'metadata-table';
    table.innerHTML = `
        <thead>
            <tr>
                <th>Metadata Field</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody></tbody>
    `;
    const tbody = table.querySelector('tbody');

    if (Object.keys(scrambled).length === 0) {
        const row = document.createElement('tr');
        row.innerHTML = `<td colspan="2">No metadata available</td>`;
        tbody.appendChild(row);
    } else {
        Object.entries(scrambled).forEach(([field, value]) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${field}</td>
                <td>${value || 'N/A'}</td>
            `;
            tbody.appendChild(row);
        });
    }

    const button = document.createElement('button');
    button.textContent = `Download`;
    button.className = 'download-button';
    button.onclick = () => {
        window.location.href = `upload.php?token=${token}`;
    };

    fileSection.appendChild(table);
    fileSection.appendChild(button);
    metadataContainer.appendChild(fileSection);
}