<template>
	<div class="post-attachment">
		<img ref='image' src='' />
	</div>
</template>

<script>
export default {
	name: 'PostAttachment',
	components: {
	},
	mixins: [],
	props: ['id'],
	data() {
		return {
		}
	},
	computed: {
	},
	mounted() {
		var image = this.$refs.image
		image.src = OC.generateUrl('/apps/social/document/get?id=' + this.id)
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
