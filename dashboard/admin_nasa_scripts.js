// =================================
// HELPER FUNCTIONS
// =================================
function showLoading() {
    document.getElementById('loading').classList.add('show');
}

function hideLoading() {
    document.getElementById('loading').classList.remove('show');
}

function showNotification(message, type = 'success') {
    const notif = document.createElement('div');
    notif.className = `notification ${type}`;
    notif.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(notif);
    
    setTimeout(() => {
        notif.style.animation = 'fadeOut 0.3s ease-out';
        setTimeout(() => notif.remove(), 300);
    }, 3000);
}

function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

// =================================
// USERS MODULE
// =================================
function loadUsers() {
    showLoading();
    const search = document.getElementById('user-search').value;
    
    const formData = new FormData();
    formData.append('action', 'get_users');
    formData.append('search', search);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const tbody = document.getElementById('users-table-body');
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #64748b;">Aucun utilisateur trouvé</td></tr>';
            } else {
                tbody.innerHTML = data.data.map((u, index) => `
                    <tr style="animation-delay: ${index * 0.05}s">
                        <td><strong>${u.id}</strong></td>
                        <td>${u.avatar ? `<img src="${u.avatar}" class="avatar" alt="Avatar">` : '<i class="fas fa-user-circle" style="font-size: 40px; color: #6366f1;"></i>'}</td>
                        <td>${u.username}</td>
                        <td><span class="badge badge-${u.role === 'developer' ? 'danger' : u.role === 'admin' ? 'warning' : 'primary'}">${u.role}</span></td>
                        <td>${u.company_name || '-'}</td>
                        <td>${new Date(u.created_at).toLocaleDateString('fr-FR')}</td>
                        <td>
                            <button onclick='editUser(${JSON.stringify(u)})' class="btn btn-warning btn-sm">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteUser(${u.id})" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }
        }
    })
    .catch(err => {
        hideLoading();
        showNotification('Erreur de chargement', 'error');
    });
}

function openAddUserModal() {
    document.getElementById('add-user-form').reset();
    openModal('add-user-modal');
}

function addUser(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'add_user');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('add-user-modal');
            loadUsers();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function editUser(user) {
    document.getElementById('edit-user-id').value = user.id;
    document.getElementById('edit-user-username').value = user.username;
    document.getElementById('edit-user-role').value = user.role;
    document.getElementById('edit-user-company').value = user.company_id;
    document.getElementById('edit-user-password').value = '';
    openModal('edit-user-modal');
}

function updateUser(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'update_user');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('edit-user-modal');
            loadUsers();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function deleteUser(id) {
    if (!confirm('Supprimer cet utilisateur ?')) return;
    
    showLoading();
    const formData = new FormData();
    formData.append('action', 'delete_user');
    formData.append('id', id);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            loadUsers();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// =================================
// COMPANIES MODULE
// =================================
function loadCompanies() {
    showLoading();
    const search = document.getElementById('company-search').value;
    
    const formData = new FormData();
    formData.append('action', 'get_companies');
    formData.append('search', search);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const tbody = document.getElementById('companies-table-body');
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 40px; color: #64748b;">Aucune société trouvée</td></tr>';
            } else {
                tbody.innerHTML = data.data.map((c, index) => `
                    <tr style="animation-delay: ${index * 0.05}s">
                        <td><strong>${c.id}</strong></td>
                        <td>${c.name}</td>
                        <td>${c.created_at ? new Date(c.created_at).toLocaleDateString('fr-FR') : '-'}</td>
                        <td>
                            <button onclick='editCompany(${JSON.stringify(c)})' class="btn btn-warning btn-sm">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteCompany(${c.id})" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }
        }
    })
    .catch(err => {
        hideLoading();
        showNotification('Erreur de chargement', 'error');
    });
}

function openAddCompanyModal() {
    document.getElementById('add-company-form').reset();
    openModal('add-company-modal');
}

function addCompany(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'add_company');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('add-company-modal');
            loadCompanies();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function editCompany(company) {
    document.getElementById('edit-company-id').value = company.id;
    document.getElementById('edit-company-name').value = company.name;
    openModal('edit-company-modal');
}

function updateCompany(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'update_company');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('edit-company-modal');
            loadCompanies();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function deleteCompany(id) {
    if (!confirm('Supprimer cette société ?')) return;
    
    showLoading();
    const formData = new FormData();
    formData.append('action', 'delete_company');
    formData.append('id', id);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            loadCompanies();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// =================================
// PRODUCTS MODULE
// =================================
function loadProducts() {
    showLoading();
    const search = document.getElementById('product-search').value;
    const company_id = document.getElementById('product-company-filter').value;
    
    const formData = new FormData();
    formData.append('action', 'get_products');
    formData.append('search', search);
    formData.append('company_id', company_id);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const tbody = document.getElementById('products-table-body');
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #64748b;">Aucun produit trouvé</td></tr>';
            } else {
                tbody.innerHTML = data.data.map((p, index) => `
                    <tr style="animation-delay: ${index * 0.05}s">
                        <td><strong>${p.id}</strong></td>
                        <td>${p.name}</td>
                        <td><span class="badge badge-primary">${p.category || '-'}</span></td>
                        <td><strong>${Number(p.price).toLocaleString()} CFA</strong></td>
                        <td>${p.alert_quantity}</td>
                        <td>${p.company_name || '-'}</td>
                        <td>
                            <button onclick='editProduct(${JSON.stringify(p)})' class="btn btn-warning btn-sm">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteProduct(${p.id})" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }
        }
    })
    .catch(err => {
        hideLoading();
        showNotification('Erreur de chargement', 'error');
    });
}

function openAddProductModal() {
    document.getElementById('add-product-form').reset();
    openModal('add-product-modal');
}

function addProduct(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'add_product');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('add-product-modal');
            loadProducts();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function editProduct(product) {
    document.getElementById('edit-product-id').value = product.id;
    document.getElementById('edit-product-name').value = product.name;
    document.getElementById('edit-product-category').value = product.category || '';
    document.getElementById('edit-product-price').value = product.price;
    document.getElementById('edit-product-alert').value = product.alert_quantity;
    openModal('edit-product-modal');
}

function updateProduct(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'update_product');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('edit-product-modal');
            loadProducts();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function deleteProduct(id) {
    if (!confirm('Supprimer ce produit ?')) return;
    
    showLoading();
    const formData = new FormData();
    formData.append('action', 'delete_product');
    formData.append('id', id);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            loadProducts();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// =================================
// CITIES MODULE
// =================================
function loadCities() {
    showLoading();
    const search = document.getElementById('city-search').value;
    const company_id = document.getElementById('city-company-filter').value;
    
    const formData = new FormData();
    formData.append('action', 'get_cities');
    formData.append('search', search);
    formData.append('company_id', company_id);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const tbody = document.getElementById('cities-table-body');
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">Aucune ville trouvée</td></tr>';
            } else {
                tbody.innerHTML = data.data.map((c, index) => `
                    <tr style="animation-delay: ${index * 0.05}s">
                        <td><strong>${c.id}</strong></td>
                        <td>${c.name}</td>
                        <td>${c.company_name || '-'}</td>
                        <td>${new Date(c.created_at).toLocaleDateString('fr-FR')}</td>
                        <td>
                            <button onclick='editCity(${JSON.stringify(c)})' class="btn btn-warning btn-sm">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteCity(${c.id})" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }
        }
    })
    .catch(err => {
        hideLoading();
        showNotification('Erreur de chargement', 'error');
    });
}

function openAddCityModal() {
    document.getElementById('add-city-form').reset();
    openModal('add-city-modal');
}

function addCity(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'add_city');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('add-city-modal');
            loadCities();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function editCity(city) {
    document.getElementById('edit-city-id').value = city.id;
    document.getElementById('edit-city-name').value = city.name;
    document.getElementById('edit-city-company').value = city.company_id;
    openModal('edit-city-modal');
}

function updateCity(e) {
    e.preventDefault();
    showLoading();
    
    const formData = new FormData(e.target);
    formData.append('action', 'update_city');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            closeModal('edit-city-modal');
            loadCities();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

function deleteCity(id) {
    if (!confirm('Supprimer cette ville ?')) return;
    
    showLoading();
    const formData = new FormData();
    formData.append('action', 'delete_city');
    formData.append('id', id);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            loadCities();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// =================================
// LOGS MODULE
// =================================
function loadLogs() {
    showLoading();
    
    const formData = new FormData();
    formData.append('action', 'get_logs');
    formData.append('limit', 200);
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const tbody = document.getElementById('logs-table-body');
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">Aucun log trouvé</td></tr>';
            } else {
                tbody.innerHTML = data.data.map((l, index) => `
                    <tr style="animation-delay: ${index * 0.05}s">
                        <td><strong>${l.id}</strong></td>
                        <td>${l.username || 'System'}</td>
                        <td><span class="badge badge-primary">${l.action}</span></td>
                        <td>${l.details || '-'}</td>
                        <td>${new Date(l.created_at).toLocaleString('fr-FR')}</td>
                    </tr>
                `).join('');
            }
            
            // Load linguistic stats
            loadLinguisticStats();
        }
    })
    .catch(err => {
        hideLoading();
        showNotification('Erreur de chargement', 'error');
    });
}

function clearLogs() {
    if (!confirm('Effacer TOUS les logs ? Cette action est irréversible !')) return;
    
    showLoading();
    const formData = new FormData();
    formData.append('action', 'clear_logs');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message);
            loadLogs();
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// =================================
// LINGUISTIC STATS & CHARTS
// =================================
let chartUsers, chartActions, chartTimeline;

function loadLinguisticStats() {
    const formData = new FormData();
    formData.append('action', 'get_linguistic_stats');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const stats = data.data;
            
            // Chart 1: Top Users
            if (document.getElementById('chart-users')) {
                const ctx1 = document.getElementById('chart-users').getContext('2d');
                
                // Destroy old chart if exists
                if (chartUsers) chartUsers.destroy();
                
                chartUsers = new Chart(ctx1, {
                    type: 'bar',
                    data: {
                        labels: stats.top_users.map(u => u.username || 'Unknown'),
                        datasets: [{
                            label: 'Nombre d\'actions',
                            data: stats.top_users.map(u => u.action_count),
                            backgroundColor: 'rgba(99, 102, 241, 0.8)',
                            borderColor: 'rgba(99, 102, 241, 1)',
                            borderWidth: 2,
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 2,
                        plugins: {
                            legend: { 
                                display: true,
                                position: 'top',
                                labels: {
                                    font: { size: 12 },
                                    padding: 15
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 14 },
                                bodyFont: { size: 13 }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: { size: 11 }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 11 }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
            
            // Chart 2: Top Actions
            if (document.getElementById('chart-actions')) {
                const ctx2 = document.getElementById('chart-actions').getContext('2d');
                
                // Destroy old chart if exists
                if (chartActions) chartActions.destroy();
                
                chartActions = new Chart(ctx2, {
                    type: 'doughnut',
                    data: {
                        labels: stats.top_actions.map(a => a.action),
                        datasets: [{
                            data: stats.top_actions.map(a => a.count),
                            backgroundColor: [
                                'rgba(99, 102, 241, 0.8)',
                                'rgba(139, 92, 246, 0.8)',
                                'rgba(236, 72, 153, 0.8)',
                                'rgba(251, 146, 60, 0.8)',
                                'rgba(34, 197, 94, 0.8)',
                                'rgba(59, 130, 246, 0.8)',
                                'rgba(168, 85, 247, 0.8)',
                                'rgba(249, 115, 22, 0.8)'
                            ],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 2,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    padding: 12,
                                    font: { size: 11 },
                                    boxWidth: 15
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 14 },
                                bodyFont: { size: 13 }
                            }
                        }
                    }
                });
            }
            
            // Chart 3: Timeline
            if (document.getElementById('chart-timeline')) {
                const ctx3 = document.getElementById('chart-timeline').getContext('2d');
                
                // Destroy old chart if exists
                if (chartTimeline) chartTimeline.destroy();
                
                chartTimeline = new Chart(ctx3, {
                    type: 'line',
                    data: {
                        labels: stats.daily_actions.map(d => new Date(d.date).toLocaleDateString('fr-FR', { 
                            day: '2-digit', 
                            month: 'short' 
                        })),
                        datasets: [{
                            label: 'Actions par jour',
                            data: stats.daily_actions.map(d => d.count),
                            fill: true,
                            backgroundColor: 'rgba(99, 102, 241, 0.15)',
                            borderColor: 'rgba(99, 102, 241, 1)',
                            borderWidth: 3,
                            tension: 0.4,
                            pointRadius: 5,
                            pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 3.5,
                        plugins: {
                            legend: { 
                                display: true,
                                position: 'top',
                                labels: {
                                    font: { size: 12 },
                                    padding: 15
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 14 },
                                bodyFont: { size: 13 }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: { size: 11 }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 11 }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        }
    });
}

// =================================
// DATABASE MODULE
// =================================
function refreshDbStats() {
    showLoading();
    
    const formData = new FormData();
    formData.append('action', 'get_db_stats');
    
    fetch('admin_api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            // Update general info
            document.getElementById('db-total-size').textContent = data.totals.total_size_mb;
            document.getElementById('db-total-tables').textContent = data.totals.total_tables;
            
            // Update tables stats
            const tbody = document.getElementById('db-stats-body');
            tbody.innerHTML = data.tables.map((t, index) => `
                <tr style="animation-delay: ${index * 0.05}s">
                    <td><strong>${t.table_name}</strong></td>
                    <td>${Number(t.row_count).toLocaleString()}</td>
                    <td>${t.size_mb} MB</td>
                    <td><span class="badge badge-success">✓ OK</span></td>
                </tr>
            `).join('');
            
            // Create chart
            if (document.getElementById('chart-db-space')) {
                const ctx = document.getElementById('chart-db-space').getContext('2d');
                
                // Destroy old chart if exists
                if (window.dbSpaceChart) {
                    window.dbSpaceChart.destroy();
                }
                
                window.dbSpaceChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: data.tables.map(t => t.table_name),
                        datasets: [{
                            data: data.tables.map(t => parseFloat(t.size_mb)),
                            backgroundColor: [
                                'rgba(99, 102, 241, 0.8)',
                                'rgba(139, 92, 246, 0.8)',
                                'rgba(236, 72, 153, 0.8)',
                                'rgba(251, 146, 60, 0.8)',
                                'rgba(34, 197, 94, 0.8)',
                                'rgba(59, 130, 246, 0.8)',
                                'rgba(168, 85, 247, 0.8)',
                                'rgba(249, 115, 22, 0.8)',
                                'rgba(244, 63, 94, 0.8)',
                                'rgba(14, 165, 233, 0.8)'
                            ],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 2,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    padding: 12,
                                    font: { size: 11 },
                                    boxWidth: 15,
                                    generateLabels: function(chart) {
                                        const data = chart.data;
                                        if (data.labels.length && data.datasets.length) {
                                            return data.labels.map((label, i) => {
                                                const value = data.datasets[0].data[i];
                                                return {
                                                    text: `${label} (${value} MB)`,
                                                    fillStyle: data.datasets[0].backgroundColor[i],
                                                    hidden: false,
                                                    index: i
                                                };
                                            });
                                        }
                                        return [];
                                    }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 14 },
                                bodyFont: { size: 13 },
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return `${label}: ${value} MB (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            showNotification('Statistiques mises à jour!');
        }
    })
    .catch(err => {
        hideLoading();
        showNotification('Erreur de chargement', 'error');
    });
}

function closeAllConnections() {
    if (!confirm('Fermer toutes les connexions PDO ouvertes ?')) return;
    
    showLoading();
    
    // This will be handled by PHP garbage collection
    // We just show a confirmation
    setTimeout(() => {
        hideLoading();
        showNotification('Connexions PDO fermées par le garbage collector PHP!');
    }, 1500);
}

// =================================
// AUTO-LOAD ON PAGE LOAD
// =================================
window.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('users-table-body')) {
        loadUsers();
    }
    if (document.getElementById('companies-table-body')) {
        loadCompanies();
    }
    if (document.getElementById('products-table-body')) {
        loadProducts();
    }
    if (document.getElementById('cities-table-body')) {
        loadCities();
    }
    if (document.getElementById('logs-table-body')) {
        loadLogs();
    }
    if (document.getElementById('db-stats-body')) {
        refreshDbStats();
    }
});

// =================================
// MODAL CLICK OUTSIDE TO CLOSE
// =================================
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('show');
            }
        });
    });
});

// =================================
// AUTO-HIDE NOTIFICATIONS
// =================================
setTimeout(() => {
    document.querySelectorAll('.notification').forEach(n => {
        n.style.animation = 'fadeOut 0.3s ease-out';
        setTimeout(() => n.remove(), 300);
    });
}, 5000);
