/**
 * SYSTÈME DE NOTIFICATIONS REAL-TIME
 * Push Notifications + Sound + Badge
 * Compatible Desktop & Mobile
 */

class NotificationSystem {
    constructor(companyId, cityId) {
        this.companyId = companyId;
        this.cityId = cityId;
        this.lastOrderId = 0;
        this.checkInterval = 10000; // 10 secondes
        this.isPolling = false;
        this.notificationSound = null;
        
        this.init();
    }
    
    async init() {
        console.log('🔔 Initialisation du système de notifications...');
        
        // 1. Demander permission
        await this.requestPermission();
        
        // 2. Charger le son
        this.loadSound();
        
        // 3. Récupérer le dernier ID
        await this.getLastOrderId();
        
        // 4. Démarrer le polling
        this.startPolling();
        
        // 5. Badge dans le titre
        this.setupTitleBadge();
        
        console.log('✅ Système de notifications actif');
    }
    
    /**
     * DEMANDER PERMISSION POUR NOTIFICATIONS
     */
    async requestPermission() {
        if (!('Notification' in window)) {
            console.warn('⚠️ Navigateur ne supporte pas les notifications');
            return false;
        }
        
        if (Notification.permission === 'granted') {
            console.log('✅ Permission notifications déjà accordée');
            return true;
        }
        
        if (Notification.permission !== 'denied') {
            const permission = await Notification.requestPermission();
            if (permission === 'granted') {
                console.log('✅ Permission notifications accordée');
                this.showWelcomeNotification();
                return true;
            }
        }
        
        console.warn('❌ Permission notifications refusée');
        return false;
    }
    
    /**
     * NOTIFICATION DE BIENVENUE
     */
    showWelcomeNotification() {
        const notification = new Notification('🔔 Notifications Activées', {
            body: 'Vous recevrez les nouvelles commandes en temps réel',
            icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="%23667eea"/><text x="50" y="65" text-anchor="middle" font-size="50" fill="white">🛒</text></svg>',
            badge: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="%2310b981"/></svg>',
            tag: 'welcome',
            requireInteraction: false
        });
        
        setTimeout(() => notification.close(), 3000);
    }
    
    /**
     * CHARGER SON DE NOTIFICATION
     */
    loadSound() {
        // Son de notification (data URI - notification bell)
        this.notificationSound = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBTGH0fPTgjMGHm7A7+OZSA0PVqzn77BdGAg+ltryxnMnBSp+zPLaizsIGGS57OihUBELTKXh8bllHAU2j9n0zoAxBxt0xPDajEELEmO48+mjUxEJSaTi8bllHAU0j9n0z4ExBx11xfDbjUEKEmS58+mjUxEJSaPi8bllHAU0kNn0z4ExBx10xPDajEEKEmS48+mjUxEJSaPi8bllHAU1j9n0z4AxBxt0xPDbjEEKEmS58+mjUhEJSqPi8bllHAU0j9n0z4ExBxt0xPDajEEKEmS58+mjUxEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEISqPi8rllHAU0j9n0z4AxBxt0xPDajEEKEmS58+mjUhEJSqPi8bllHAU0kNn0z4AxBxt0xPDajEEKEmS58+mjUhEJSqPi8rllHAU0kNn0z4AxBxt0xPDajUEKEmS58+mjUhEJSqPi8rllHAU0kNn0z4AxBxt0xPDajUEKEmS58+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4AxBx10xPDajEEKEmS48+mjUhEJSqPi8bllHAU1kNn0z4AxBx10xPDajEEKEmW48+mjUhEJSqPi8rllHAU1kNn0z4A=');
    }
    
    /**
     * JOUER SON
     */
    playSound() {
        if (this.notificationSound) {
            this.notificationSound.play().catch(err => {
                console.log('Son de notification bloqué par le navigateur');
            });
        }
    }
    
    /**
     * RÉCUPÉRER LE DERNIER ORDER ID
     */
    async getLastOrderId() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_last_order_id');
            formData.append('company_id', this.companyId);
            formData.append('city_id', this.cityId);
            
            const response = await fetch('notifications_api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.success && data.last_id) {
                this.lastOrderId = data.last_id;
                console.log(`📌 Dernier Order ID: ${this.lastOrderId}`);
            }
        } catch (err) {
            console.error('Erreur getLastOrderId:', err);
        }
    }
    
    /**
     * DÉMARRER LE POLLING
     */
    startPolling() {
        if (this.isPolling) return;
        
        this.isPolling = true;
        console.log('🔄 Polling démarré (vérification toutes les 10s)');
        
        this.pollingInterval = setInterval(() => {
            this.checkNewOrders();
        }, this.checkInterval);
        
        // Vérification immédiate
        this.checkNewOrders();
    }
    
    /**
     * ARRÊTER LE POLLING
     */
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.isPolling = false;
            console.log('⏹️ Polling arrêté');
        }
    }
    
    /**
     * VÉRIFIER NOUVELLES COMMANDES
     */
    async checkNewOrders() {
        try {
            const formData = new FormData();
            formData.append('action', 'check_new_orders');
            formData.append('company_id', this.companyId);
            formData.append('city_id', this.cityId);
            formData.append('last_order_id', this.lastOrderId);
            
            const response = await fetch('notifications_api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success && data.new_orders && data.new_orders.length > 0) {
                console.log(`🔔 ${data.new_orders.length} nouvelle(s) commande(s)!`);
                
                // Traiter chaque nouvelle commande
                data.new_orders.forEach(order => {
                    this.showOrderNotification(order);
                });
                
                // Mettre à jour le dernier ID
                this.lastOrderId = data.new_orders[data.new_orders.length - 1].id;
                
                // Recharger la liste des commandes
                if (typeof loadOrders === 'function') {
                    loadOrders();
                }
                
                // Mettre à jour les stats
                if (typeof updateStats === 'function') {
                    updateStats();
                }
            }
        } catch (err) {
            console.error('Erreur checkNewOrders:', err);
        }
    }
    
    /**
     * AFFICHER NOTIFICATION COMMANDE
     */
    showOrderNotification(order) {
        // Jouer le son
        this.playSound();
        
        // Badge titre
        this.flashTitleBadge();
        
        // Notification système
        if (Notification.permission === 'granted') {
            const notification = new Notification('🛒 Nouvelle Commande!', {
                body: `${order.client_name}\n💰 ${parseInt(order.total_amount).toLocaleString()} CFA\n📦 ${order.items_count} article(s)`,
                icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="%2310b981"/><text x="50" y="65" text-anchor="middle" font-size="50" fill="white">🛒</text></svg>',
                badge: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="%23ef4444"/></svg>',
                tag: `order-${order.id}`,
                requireInteraction: true,
                vibrate: [200, 100, 200],
                data: { orderId: order.id }
            });
            
            // Clic sur notification
            notification.onclick = (e) => {
                window.focus();
                notification.close();
                
                // Ouvrir les détails de la commande
                if (typeof viewOrder === 'function') {
                    viewOrder(order.id);
                }
            };
            
            // Auto-close après 10 secondes
            setTimeout(() => notification.close(), 10000);
        }
    }
    
    /**
     * BADGE DANS LE TITRE
     */
    setupTitleBadge() {
        this.originalTitle = document.title;
    }
    
    flashTitleBadge() {
        let count = 0;
        const maxFlash = 10;
        
        const flashInterval = setInterval(() => {
            document.title = count % 2 === 0 ? '🔴 NOUVELLE COMMANDE!' : this.originalTitle;
            count++;
            
            if (count >= maxFlash) {
                clearInterval(flashInterval);
                document.title = this.originalTitle;
            }
        }, 500);
    }
    
    /**
     * METTRE À JOUR LES IDs (si changement de localisation)
     */
    updateLocation(companyId, cityId) {
        console.log(`📍 Mise à jour localisation: Company ${companyId}, City ${cityId}`);
        this.companyId = companyId;
        this.cityId = cityId;
        this.lastOrderId = 0;
        this.getLastOrderId();
    }
}

// Export pour utilisation globale
window.NotificationSystem = NotificationSystem;
