class Client {
	constructor(server) {
		this.server = server

		this.callbacks = {}
		this.queries = []

		this.connect()

		this.timeout = 25
		this.timer = setInterval(() => {
			this.timeout -= 1
			if (this.timeout <= 0) {
				this.emit('keepAlive')
				this.timeout = 25
			}
		}, 1000)
	}

	emit(action, args = []) {
		let payload = JSON.stringify([action, args, this.uuid(), localStorage.getItem('Access-Token')])
		if (this.ws.readyState != 1) {
			this.queries.push(payload)
		}
		else {
			this.timeout = 25
			this.send(payload)
		}
	}

	on(action, callback) {
		this.callbacks[action] = callback
	}

	connect() {
		this.ws = new WebSocket(this.server)
		this.ws.onopen = (msg) => {
			this.emit('keepAlive')

			let queries = this.queries
			this.queries = []
			for (let i = 0; i < queries.length; i += 1) {
				this.send(queries[i])
			}
		}
		this.ws.onmessage = (event) => {
			let data = JSON.parse(event.data)
			if (data) {
				let [action, payload] = data
				if (this.callbacks[action]) {
					let callback = this.callbacks[action]
					let [successed, result] = payload
					callback(successed, result)
				}
				else {
					console.warn('no action', action, payload)
				}
			}
			else {
				console.error('format error', event.data)
			}
			this.timeout = 25
		}
		this.ws.onerror = (error) => {
			console.error('error', error)
		}
		this.ws.onclose = (msg) => {
			setTimeout(() => this.connect(), 3000)
		}
	}

	send(msg) {
		this.ws.send(msg)
	}

	close() {
		this.ws.close()
	}

	uuid() {
		function random(length) {
			let chars = '1234567890abcdef'
			let cs = []
			for (let i = 0; i < length; i += 1) {
				cs.push(chars[Math.floor(Math.random() * 16)])
			}
			return cs.join('')
		}

		return `${random(8)}-${random(4)}-${random(4)}-${random(4)}-${random(12)}`
	}
}
