set names utf8;

create table user(
	id int not null primary key auto_increment,
	type tinyint not null default 1, -- 1-private,2-channel,3-group,4-bot
	username varchar(30) not null,
	title varchar(50) not null default '',
	avatar char(36) null, -- objId
	bio varchar(250) not null default '',
	verified tinyint not null default 0,
	salt char(8) not null,
	password char(32) not null,
	owner int not null default 0,
	unique(username),
	index(type),
	index(owner)	-- channel, group, bot
);

create table upload(
	id int not null primary key auto_increment,
	objId char(36) not null,
	mime varchar(30) not null,
	size int not null,
	senderId int not null,
	ctime int not null,
	unique(objId)
);

create table user_subscriber(
	id int not null primary key auto_increment,
	userId int not null,
	subscriberId int not null,
	status tinyint not null default 1, -- 1-normal,2-ban,3-deleted
	type tinyint not null, -- 1-admin,2-subscriber
	index(userId),
	index(subscriberId),
	index(type)
);

create table message(
	id int not null primary key auto_increment,
	senderId int not null,
	receiverId int not null,
	type tinyint not null, -- 1-text,2-sticker,3-photos,4-video,5-audio
	content text not null,
	ctime int not null,
	index(receiverId),
	index(senderId),
	index(ctime)
);
