class Chat {
	constructor(server) {
		this.accessToken = null
		this.profile = null

		this.client = new Client(server)

		this.client.on('refreshToken', this.onRefreshToken.bind(this))
		this.client.on('signUp', this.onSignUp.bind(this))
		this.client.on('signIn', this.onSignIn.bind(this))
		this.client.on('signOut', this.onSignOut.bind(this))
		this.client.on('userInfo', this.onUserInfo.bind(this))
		this.client.on('sendMessage', this.onSendMessage.bind(this))
	}

	startUp() {
		var accessToken = localStorage.getItem('Access-Token')
		if (!accessToken) {
			this.refreshToken()
		}
		else {
			var userInfo = localStorage.getItem('userInfo')
			if (userInfo) {
				this.profile = JSON.parse(userInfo)
				this.userInfo()
			}
		}
	}

	refreshToken() {
		this.client.emit('refreshToken')
	}

	onRefreshToken(successded, token) {
		if (successded) {
			localStorage.setItem('Access-Token', token)
		}
		else {
			this.refreshToken()
		}
	}

	signUp(username, password, nickname) {
		this.client.emit('signUp', [username, password, nickname])
	}

	onSignUp(successded, payload) {
		if (successded) {
			this.userInfo()
		}
		else {
			alert(payload)
		}
	}

	signIn(username, password) {
		this.client.emit('signIn', [username, password])
	}

	onSignIn(successded, payload) {
		if (successded) {
			this.userInfo()
		}
		else {
			alert(payload)
		}
	}

	signOut() {
		this.client.emit('signOut')
	}

	onSignOut(successded, reason) {
		if (reason) {
			alert(reason)
		}
		this.refreshToken()
	}

	userInfo() {
		this.client.emit('userInfo')
	}

	onUserInfo(successded, profile) {
		if (successded) {
			this.profile = profile
			localStorage.setItem('userInfo', JSON.stringify(profile))
		}
		else {
			console.warn(profile)
		}
	}

	sendMessage(chatId, message) {
		this.client.emit('sendMessage', [chatId, message])
	}

	onSendMessage(successded, data) {
		if (!successded) {
			alert(data)
		}
		else {
			console.log('received', data)
		}
	}
}
