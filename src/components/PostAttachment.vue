<template>
	<div class="post-attachment">
		<img :src='url' />
	</div>
</template>

<script>
export default {
	name: 'PostAttachment',
	components: {
	},
	mixins: [],
	props: ['url'],
	data() {
		return {
		}
	},
	computed: {
	},
	methods: {
	}
}
</script>

<style scoped lang="scss">
	.post-attachment {
		display: flex;
		flex-grow: 1;
	}
</style>
